<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordChangedMail;
use App\Mail\SendOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    /**
     * Step 1: Send OTP via email to the given address
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email'    => 'Ingresa un correo electrónico válido.',
            'email.exists'   => 'Este correo no está registrado.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $otp   = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $token = Str::random(64);

            // Invalidate previous OTPs for this email
            PasswordResetOtp::where('email', $request->email)
                ->where('is_used', false)
                ->update(['is_used' => true]);

            PasswordResetOtp::create([
                'email'      => $request->email,
                'otp'        => $otp,
                'token'      => $token,
                'expires_at' => now()->addMinutes(10),
            ]);

            $user = User::where('email', $request->email)->first();

            $sent = $this->sendOtpViaEmail($user, $otp);

            if (!$sent) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo enviar el código por correo. Intente nuevamente.',
                ], 500);
            }

            Log::info('OTP sent via email', ['user_id' => $user->id, 'email' => $request->email]);

            return response()->json([
                'success' => true,
                'message' => 'Código enviado por correo electrónico',
                'data'    => [
                    'email'      => $request->email,
                    'masked'     => $this->maskEmail($request->email),
                    'expires_in' => 600,
                    'countdown'  => 60,
                    'channels'   => ['email'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('OTP generation failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el código',
            ], 500);
        }
    }

    /**
     * Send the OTP email
     */
    protected function sendOtpViaEmail(User $user, string $otp): bool
    {
        try {
            Mail::to($user->email)->send(new SendOtpMail($otp, $user->name));
            return true;
        } catch (\Exception $e) {
            Log::error('OTP email send failed', [
                'error' => $e->getMessage(),
                'email' => $user->email,
            ]);
            return false;
        }
    }

    /**
     * Step 2: Verify OTP
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'otp'   => 'required|string|size:6',
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.exists'   => 'Este correo no está registrado.',
            'otp.required'   => 'El código de verificación es obligatorio.',
            'otp.size'       => 'El código de verificación debe tener 6 dígitos.',
            'otp.digits'     => 'El código de verificación es incorrecto.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $resetOtp = PasswordResetOtp::where('email', $request->email)
            ->where('otp', $request->otp)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$resetOtp) {
            return response()->json([
                'success' => false,
                'message' => 'El código de verificación es incorrecto o ha expirado. Por favor, solicita uno nuevo.',
                'error'   => 'invalid_otp',
            ], 400);
        }

        $resetOtp->update(['expires_at' => now()->addMinutes(15)]);

        return response()->json([
            'success' => true,
            'message' => 'Código verificado correctamente',
            'data'    => [
                'reset_token' => $resetOtp->token,
                'email'       => $request->email,
                'next_step'   => 'new_password',
            ],
        ]);
    }

    /**
     * Step 3: Reset Password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'                 => 'required|email|exists:users,email',
            'reset_token'           => 'required|string',
            'password'              => 'required|string|min:6',
            'password_confirmation' => 'required|string|same:password',
        ], [
            'email.required'                 => 'El correo electrónico es requerido',
            'email.exists'                   => 'Este correo no está registrado',
            'reset_token.required'           => 'El token de recuperación es obligatorio.',
            'password.required'              => 'La contraseña es obligatoria.',
            'password.min'                   => 'La contraseña debe tener al menos 6 caracteres.',
            'password_confirmation.same'     => 'Las contraseñas no coinciden',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $resetOtp = PasswordResetOtp::where('email', $request->email)
            ->where('token', $request->reset_token)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$resetOtp) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido o expirado',
                'error'   => 'invalid_token',
            ], 400);
        }

        try {
            $user = User::where('email', $request->email)->first();

            $user->update(['password' => Hash::make($request->password)]);
            $resetOtp->markAsUsed();
            $user->tokens()->delete();

            $this->notifyPasswordChanged($user);

            $newToken = $user->createToken('password_reset', ['driver:access'])->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada correctamente',
                'data'    => [
                    'auth_token' => $newToken,
                    'user'       => [
                        'id'    => $user->id,
                        'name'  => $user->name,
                        'email' => $user->email,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Password reset failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar contraseña',
            ], 500);
        }
    }

    /**
     * Resend OTP
     */
    public function resendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.exists'   => 'Este correo no está registrado.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $recentOtp = PasswordResetOtp::where('email', $request->email)
            ->where('is_used', false)
            ->where('created_at', '>', now()->subSeconds(60))
            ->first();

        if ($recentOtp) {
            $remaining = 60 - now()->diffInSeconds($recentOtp->created_at);
            return response()->json([
                'success'   => false,
                'message'   => 'Espere antes de reenviar',
                'countdown' => (int) $remaining,
            ], 429);
        }

        return $this->sendOtp($request);
    }

    protected function notifyPasswordChanged(User $user): void
    {
        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user->name));
        } catch (\Exception $e) {
            Log::error('Password change email notify failed', ['error' => $e->getMessage()]);
        }
    }

    protected function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email) + [1 => ''];
        $visible = min(2, strlen($local));
        $masked = substr($local, 0, $visible) . str_repeat('*', max(strlen($local) - $visible, 3));
        return $masked . '@' . $domain;
    }
}
