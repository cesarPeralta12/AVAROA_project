<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Custom JSON response for validation errors.
     * Garantiza que la API NUNCA devuelva un genérico "Validation Error":
     * `message` siempre contiene el primer error específico (ej. "Este correo
     * ya está registrado.") y `errors` mantiene el detalle por campo.
     */
    protected function invalidJson($request, ValidationException $exception)
    {
        $errors = $exception->errors();
        $firstMessage = null;
        foreach ($errors as $fieldErrors) {
            if (is_array($fieldErrors) && !empty($fieldErrors)) {
                $firstMessage = (string) $fieldErrors[0];
                break;
            }
        }

        return response()->json([
            'success' => false,
            'message' => $firstMessage ?: 'Por favor, revisa los campos del formulario.',
            'errors'  => $errors,
        ], $exception->status);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Illuminate\Http\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        // CSRF token mismatch (session expired while idle / page left open AFK).
        // Send the user back to the right login screen with a clear message
        // instead of Laravel's raw "419 | PAGE EXPIRED" page.
        if ($exception instanceof \Illuminate\Session\TokenMismatchException) {
            $message = 'Tu sesión expiró. Por favor, inicia sesión nuevamente.';

            if ($request->is('admin/*')) {
                return redirect('admin/login')->with('fail', $message);
            }

            return redirect('/')->with('fail', $message);
        }

        // API requests get clean JSON for ModelNotFoundException instead of raw PHP exception
        if ($exception instanceof ModelNotFoundException && $request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'El recurso solicitado no existe o ya no está disponible.',
            ], 404);
        }

        // Check if the exception is an HttpException
        if ($exception instanceof HttpException) {
            $statusCode = $exception->getStatusCode();

            // Log the error for critical status codes
            if (in_array($statusCode, [500, 503, 429])) {
                Log::error("HTTP Exception: {$statusCode}", [
                    'url' => $request->url(),
                    'message' => $exception->getMessage(),
                ]);
            }

            // Return the custom error view based on the status code
            switch ($statusCode) {
                case 400: // Bad Request
                    return response()->view('others.error_pages.error_page1', [], 400);
                case 401: // Unauthorized
                    return response()->view('others.error_pages.error_page2', [], 401);
                case 403: // Forbidden
                    return response()->view('others.error_pages.error_page3', [], 403);
                case 404: // Not Found
                    return response()->view('others.error_pages.error_page5', [], 404);
                case 408: // Request Timeout
                    return response()->view('others.error_pages.error_page408', [], 408);
                case 429: // Too Many Requests
                    return response()->view('others.error_pages.error_page429', [], 429);
                case 500: // Internal Server Error
                    return response()->view('others.error_pages.error_page4', [], 500);
                case 503: // Service Unavailable
                    return response()->view('others.error_pages.error_page503', [], 503);
                default: // For unhandled HTTP status codes
                    return response()->view('others.error_pages.generic_error', ['statusCode' => $statusCode], $statusCode);
            }
        }

        // For non-HttpExceptions or unhandled exceptions
        return parent::render($request, $exception);
    }
}
