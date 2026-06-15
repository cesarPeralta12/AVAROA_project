<?php

namespace App\Http\Controllers\Admin;


use App\Helpers\FirebaseHelper;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Mail\SendMailreset;
use App\Models\BackgroundCertificate;

use App\Models\City;
use App\Models\Trip;
use App\Models\Driver;
use App\Models\DriverRequest;
use App\Models\WalletTransaction;
use App\Models\ProofOfDelivery;
use App\Models\Country;
use App\Models\File;
use App\Models\Folder;
use App\Models\IdentityCard;
use App\Models\Notification;

use App\Models\PasswordReset;
use App\Models\TradeLog;
use App\Models\User;

use App\Notifications\VerifyEmailNotification;
use App\Traits\SendNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash as FacadesHash;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;




class Admin extends Controller
{
    use SendNotification;
    public function admin()
    {
        return view('admin.admin');
    }



    public function notificationUrl($uuid)
    {
        $notification = Notification::whereUuid($uuid)->first();
        $notification->is_seen = 'yes';
        $notification->save();

        if (is_null($notification->target_url)) {
            return redirect(url()->previous());
        } else {
            return redirect($notification->target_url);
        }
    }

    public function markAllAsReadNotification(Request $request)
    {
        $userId = $request->input('user_id');
        $data = User::find($userId);



        Notification::where('user_id', $userId)->where('is_seen', 'no')->update(['is_seen' => 'yes']);


        return back();
    }
    public function getNotifications()
    {
        // Fetch notifications for the logged-in user
        $notifications = Notification::where('user_id', Session::get('LoggedIn'))
            ->orderBy('created_at', 'desc')
            ->take(5) // Limit to latest 5 notifications (adjust this based on your requirement)
            ->get();

        // Assuming you want to return a structure that includes text, target_url, and created_at
        $notifications = $notifications->map(function ($notification) {
            return [
                'text' => $notification->text,
                'target_url' => $notification->target_url,
                'created_at' => $notification->created_at->toIso8601String(), // Ensure it's in a format JS can work with
            ];
        });

        return response()->json(['notifications' => $notifications]);
    }


    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return back()->with('fail', 'Email is not registered');
        }

        if (!Hash::check($request->password, $user->password)) {
            return back()->with('fail', 'Password does not match');
        }

        $role = $user->is_super_admin ? 'admin' : ($user->role ?? 'customer');

        if (!in_array($role, ['admin', 'asistente'])) {
            return back()->with('fail', 'No tienes permisos para acceder al panel de administración.');
        }

        // ✅ IMPORTANT (THIS FIXES user_id in sessions)
        Auth::login($user);

        // Optional custom session
        session()->put('LoggedIn', $user->id);

        $user->update([
            'is_online' => 1,
            'last_seen' => now()
        ]);

        // Asistentes land on topup-requests since they can't access dashboard
        if ($role === 'asistente') {
            return redirect('admin/topup-requests');
        }

        return redirect('admin/dashboard');
    }
    public function getMessages()
    {
        // Fetch messages where receiver_id is the logged-in user (assuming user authentication)
        $messages = DB::table('chats')
            ->join('users', 'chats.sender_id', '=', 'users.id') // Join with users table to get sender's details
            ->where('receiver_id', Session::get('LoggedIn')) // Fetch messages for the logged-in user
            ->whereNull('chats.deleted_at')  // Exclude deleted messages
            ->orderBy('chats.created_at', 'desc') // Order by most recent
            ->limit(3) // Limit to 5 messages for the dropdown
            ->select('chats.*', 'users.name as sender_name', 'users.profile_photo') // Select necessary fields
            ->get();

        // Return messages with additional sender name and profile photo
        return response()->json(['messages' => $messages]);
    }



    public function dashboard(Request $request)
    {
        if (!Session::has('LoggedIn')) {
            return redirect('/admin/login')->with('fail', 'Por favor inicie sesión primero.');
        }

        $user_session = User::find(Session::get('LoggedIn'));

        // Fechas para filtros
        $today = Carbon::today();
        $startOfWeek = Carbon::now()->startOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();

        // Métricas reales de usuarios
        $totalUsers = User::count();
        $totalCustomers = User::where('role', 'customer')->count();
        $totalDrivers = User::where('role', 'driver')->count();
        $newUsersToday = User::whereDate('created_at', $today)->count();

        // Métricas de viajes
        $todayOrders = Trip::whereDate('created_at', $today)->count();
        $weekOrders = Trip::whereDate('created_at', '>=', $startOfWeek)->count();
        $monthOrders = Trip::whereDate('created_at', '>=', $startOfMonth)->count();
        $totalOrders = Trip::count();

        // Estados de viajes
        $activeDeliveries = Trip::whereIn('status', ['accepted', 'driver_arrived', 'picked_up', 'in_progress'])->count();
        $pendingJobs = Trip::where('status', 'pending')->count();
        $completedToday = Trip::where('status', 'completed')->whereDate('completed_at', $today)->count();
        $cancellationsToday = Trip::where('status', 'cancelled')->whereDate('cancelled_at', $today)->count();
        $totalCancellations = Trip::where('status', 'cancelled')->count();

        // Métricas de conductores
        $onlineDrivers = Driver::where('is_online', 1)->where('status', 'available')->count();
        $busyDrivers = Driver::where('status', 'busy')->count();
        $offlineDrivers = Driver::where('is_online', 0)->orWhereNull('is_online')->count();
        $pendingApproval = Driver::where('approval_status', 'pending')->count();

        // Finanzas
        $todayRevenue = Trip::whereDate('completed_at', $today)->where('status', 'completed')->sum('price') ?? 0;
        $weekRevenue = Trip::whereDate('completed_at', '>=', $startOfWeek)->where('status', 'completed')->sum('price') ?? 0;
        $totalRevenue = Trip::where('status', 'completed')->sum('price') ?? 0;

        // Transacciones de billetera
        $pendingTopups = \App\Models\TopUpRequest::where('status', 'pending')->count();
        $totalWalletBalance = \App\Models\Wallet::sum('balance') ?? 0;

        // SLA y rendimiento
        try {
            $avgDeliveryTime = Trip::where('status', 'completed')
                ->whereNotNull('completed_at')
                ->whereNotNull('accepted_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, accepted_at, completed_at)) as avg_time')
                ->first()
                ->avg_time ?? 0;
        } catch (\Exception $e) {
            $avgDeliveryTime = 0;
        }

        try {
            $onTimeDeliveries = Trip::where('status', 'completed')
                ->whereNotNull('eta')
                ->whereNotNull('completed_at')
                ->whereRaw('completed_at <= DATE_ADD(accepted_at, INTERVAL eta MINUTE)')
                ->count();
        } catch (\Exception $e) {
            $onTimeDeliveries = 0;
        }

        $completedCount = Trip::where('status', 'completed')->count();
        $slaPercentage = $completedCount > 0 ? round(($onTimeDeliveries / $completedCount) * 100, 1) : 0;

        // Datos para gráficos - Tendencia de 7 días
        $ordersTrend  = [];
        $revenueTrend = [];
        $labels       = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $labels[] = $date->format('D');

            $ordersTrend[] = Trip::whereDate('created_at', $date)->count();
            $revenueTrend[] = (float) (Trip::whereDate('completed_at', $date)
                ->where('status', 'completed')
                ->sum('price') ?? 0);
        }

        // Datos para gráfico de estado de entregas
        $deliveryStatus = [
            'active'    => $activeDeliveries,
            'completed' => Trip::where('status', 'completed')->count(),
            'cancelled' => Trip::where('status', 'cancelled')->count(),
            'pending'   => $pendingJobs,
            'searching' => Trip::where('status', 'searching')->count(),
        ];

        // Viajes recientes
        $recentTrips = Trip::with(['customer', 'driver'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        // Top conductores
        $topDrivers = Driver::with('user')
            ->whereHas('user')
            ->withCount(['trips as completed_trips' => function($q) {
                $q->where('status', 'completed');
            }])
            ->orderByDesc('completed_trips')
            ->take(5)
            ->get();

        // Solicitudes de recarga pendientes
        $pendingTopupRequests = \App\Models\TopUpRequest::with(['driver.user', 'wallet'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return view('dashboards.default_dashboard', compact(
            'user_session',
            'totalUsers',
            'totalCustomers',
            'totalDrivers',
            'newUsersToday',
            'todayOrders',
            'weekOrders',
            'monthOrders',
            'totalOrders',
            'activeDeliveries',
            'pendingJobs',
            'completedToday',
            'cancellationsToday',
            'totalCancellations',
            'onlineDrivers',
            'busyDrivers',
            'offlineDrivers',
            'pendingApproval',
            'todayRevenue',
            'weekRevenue',
            'totalRevenue',
            'pendingTopups',
            'totalWalletBalance',
            'avgDeliveryTime',
            'slaPercentage',
            'labels',
            'ordersTrend',
            'revenueTrend',
            'deliveryStatus',
            'recentTrips',
            'topDrivers',
            'pendingTopupRequests'
        ));
    }

    public function chartData(Request $request)
    {
        try {
            $period = (int) $request->get('period', 7);
            if (!in_array($period, [7, 30, 90])) {
                $period = 7;
            }

            $ordersTrend = [];
            $revenueTrend = [];
            $labels = [];

            for ($i = $period - 1; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $labels[] = $period <= 7 ? $date->format('D') : $date->format('d/m');
                $ordersTrend[] = Trip::whereDate('created_at', $date)->count();
                $revenueTrend[] = (float) (Trip::whereDate('completed_at', $date)
                    ->where('status', 'completed')
                    ->sum('price') ?? 0);
            }

            return response()->json([
                'success' => true,
                'labels' => $labels,
                'ordersTrend' => $ordersTrend,
                'revenueTrend' => $revenueTrend,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getRealtimeStats()
    {
        try {
            $today = Carbon::today();

            return response()->json([
                'success' => true,
                'online_drivers' => Driver::where('is_online', 1)->where('status', 'available')->count(),
                'active_deliveries' => Trip::whereIn('status', ['accepted', 'driver_arrived', 'picked_up', 'in_progress'])->count(),
                'pending_requests' => Trip::where('status', 'pending')->count(),
                'today_revenue' => Trip::whereDate('completed_at', $today)->where('status', 'completed')->sum('price') ?? 0,
                'today_orders' => Trip::whereDate('created_at', $today)->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }






    public function updateMode(Request $request)
    {


        // Get the logged-in user
        $user_session = User::where('id', Session::get('LoggedIn'))->first();

        // If no user is found, return an error response
        if (!$user_session) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        // Update the mode in the user's session record
        $user_session->mode = $request->mode;
        $user_session->save();

        // Return success response
        return response()->json(['success' => true, 'message' => 'Mode updated successfully']);
    }

    public function getUserMode()
    {
        // Get the logged-in user
        $user_session = User::where('id', Session::get('LoggedIn'))->first();

        // If no user is found, return an error response or default 'light'
        if (!$user_session) {
            return response()->json(['mode' => 'light'], 200); // Or you can return an error response
        }

        // Return the user's mode, or default to 'light' if not set
        return response()->json(['mode' => $user_session->mode ?? 'light'], 200);
    }



    public function flag_icon(Request $request)
    {
        if (Session::has('LoggedIn')) {



            $user_session = User::where('id', Session::get('LoggedIn'))->first();



            return view('icons.flag_icon', compact('user_session'));
        }
    }
    public function font_awesome(Request $request)
    {
        if (Session::has('LoggedIn')) {



            $user_session = User::where('id', Session::get('LoggedIn'))->first();



            return view('icons.font_awesome', compact('user_session'));
        }
    }
    public function ico_icon(Request $request)
    {
        if (Session::has('LoggedIn')) {



            $user_session = User::where('id', Session::get('LoggedIn'))->first();



            return view('icons.ico_icon', compact('user_session'));
        }
    }
    public function themify_icon(Request $request)
    {
        if (Session::has('LoggedIn')) {



            $user_session = User::where('id', Session::get('LoggedIn'))->first();



            return view('icons.themify_icon', compact('user_session'));
        }
    }
    public function feather_icon(Request $request)
    {
        if (Session::has('LoggedIn')) {



            $user_session = User::where('id', Session::get('LoggedIn'))->first();



            return view('icons.feather_icon', compact('user_session'));
        }
    }
    public function whether_icon(Request $request)
    {
        if (Session::has('LoggedIn')) {



            $user_session = User::where('id', Session::get('LoggedIn'))->first();



            return view('icons.whether_icon', compact('user_session'));
        }
    }
    public function balanceManagement()
    {
        if (!Session::has('LoggedIn')) {
            return redirect('/admin/login')->with('fail', 'Por favor inicia sesión');
        }

        try {
            $auth = FirebaseHelper::auth();
            $users = $auth->listUsers();
            $chambeadors = [];
            $user_session = User::where('id', Session::get('LoggedIn'))->first();
            foreach ($users as $user) {
                $profile = ChambeadorProfile::where('uid', $user->uid)->first();
                if ($profile) {
                    $chambeadors[] = [
                        'uid' => $user->uid,
                        'email' => $user->email,
                        'name' => $profile->name,
                        'last_name' => $profile->last_name,
                        'balance' => $profile->balance ?? 0,
                        'profession' => $profile->profession,
                        'phone' => $profile->phone,
                    ];
                }
            }

            $workersWithBalance = ChambeadorProfile::whereNotNull('balance')
                ->where('balance', '>', 0)
                ->select('uid', 'name', 'last_name', 'balance', 'email', 'updated_at')
                ->orderBy('updated_at', 'desc')
                ->get();

            return view('admin.balance-management', compact('chambeadors', 'user_session', 'workersWithBalance'));
        } catch (\Exception $e) {
            Log::error('Error loading balance management: ' . $e->getMessage());
            return redirect()->back()->with('fail', 'Error al cargar la gestión de saldos');
        }
    }
    /**
     * Add balance to worker after deposit verification
     */
    public function addBalance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'uid' => 'required|string',
            'deposit_amount' => 'required|numeric|min:0',
            'admin_note' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            Log::error('Validation error in addBalance', [
                'uid' => $request->uid,
                'errors' => $validator->errors(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $profile = ChambeadorProfile::where('uid', $request->uid)->first();

            if (!$profile) {
                Log::error('Worker profile not found in addBalance', [
                    'uid' => $request->uid,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Perfil de trabajador no encontrado.'
                ], 404);
            }

            $depositAmount = floatval($request->deposit_amount);

            // Update worker balance with full deposit amount
            $profile->balance = ($profile->balance ?? 0) + $depositAmount;
            $profile->save();

            // Log the transaction
            Log::info('Balance added', [
                'uid' => $request->uid,
                'deposit_amount' => $depositAmount,
                'total_balance' => $profile->balance,
                'admin_note' => $request->admin_note ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Saldo agregado exitosamente.',
                'data' => [
                    'deposit_amount' => number_format($depositAmount, 2),
                    'new_balance' => number_format($profile->balance, 2),
                    'worker_name' => $profile->name . ' ' . $profile->last_name
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding balance: ' . $e->getMessage(), [
                'uid' => $request->uid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la adición de saldo.'
            ], 500);
        }
    }

    /**
     * Get balance history for admin
     */
    public function getBalanceHistory(Request $request)
    {
        $perPage = 5;
        $page = $request->query('page', 1);

        $query = DB::table('chambeador_profiles')
            ->select('uid', 'name', 'last_name', 'email', 'balance', 'updated_at')
            ->whereNotNull('balance')
            ->where('balance', '>', 0);

        $workers = $query->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        // Log the query results for debugging
        Log::info('Balance History Query', [
            'total' => $workers->total(),
            'data' => $workers->items(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $workers->items(),
            'pagination' => [
                'current_page' => $workers->currentPage(),
                'last_page' => $workers->lastPage(),
                'per_page' => $workers->perPage(),
                'total' => $workers->total(),
            ],
        ]);
    }







    public function users()
    {
        if (!session()->has('LoggedIn')) {
            return redirect('/admin/login');
        }

        // Fetch users directly from your local database (e.g., 'users' table)
        $usersData = User::where('is_super_admin', '!=', 1)->where('account_type','customer')->get();



        $user_session = User::find(session()->get('LoggedIn'));

        return view('admin.users', compact('usersData', 'user_session'));
    }



    public function edit_user(Request $request, $id)
    {
        if (Session::has('LoggedIn')) {
            $userData = DB::table("users")->where('id', $id)->where('is_super_admin', '0')->first();
            $user_session = User::where('id', Session::get('LoggedIn'))->first();
            $countries = Country::all();
            $cities = City::all();
            return view('admin/edit_user', compact('user_session', 'userData', 'countries', 'cities'));
        }
    }

    public function change_password(Request $request)
    {

        $data = array();
        if (Session::has('LoggedIn')) {
            $user_session = User::where('id', '=', Session::get('LoggedIn'))->first();
        }

        return view('admin.change_password', compact('user_session'));
    }

    public function update_password(Request $request)
    {
        $request->validate([
            'old_password'     => 'required',
            'new_password'     => 'required|min:6',
            'confirm_password' => 'required|same:new_password',
        ], [
            'old_password.required'     => 'La contraseña actual es obligatoria.',
            'new_password.required'     => 'La nueva contraseña es obligatoria.',
            'new_password.min'          => 'La nueva contraseña debe tener al menos 6 caracteres.',
            'confirm_password.required' => 'Debes confirmar la nueva contraseña.',
            'confirm_password.same'     => 'Las contraseñas nuevas no coinciden.',
        ]);

        $user = User::find(Session::get('LoggedIn'));

        if (!FacadesHash::check($request->old_password, $user->password)) {
            return back()->with('fail', 'La contraseña actual es incorrecta.');
        }

        if (FacadesHash::check($request->new_password, $user->password)) {
            return back()->with('fail', 'La nueva contraseña no puede ser igual a la actual.');
        }

        $user->update(['password' => FacadesHash::make($request->new_password)]);

        return back()->with('success', 'Contraseña actualizada correctamente.');
    }



    public function profile(Request $request)
    {
        $data = array();
        if (Session::has('LoggedIn')) {
            $data = User::where('id', '=', Session::get('LoggedIn'))->first();
        }

        return view('admin.profile', compact('data'));
    }

   public function logout(Request $request)
{
    Auth::logout(); // ✅ IMPORTANT

    Session::forget('LoggedIn');
    Session::forget('user_session');

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('admin/login');
}
    public function add_user()
    {
        if (Session::has('LoggedIn')) {

            $countries = Country::all();
            $cities = City::all();
            $user_session = User::where('id', Session::get('LoggedIn'))->first();

            return view('admin.add_user', compact('user_session', 'countries', 'cities'));
        }
    }

    public function save_user(Request $request)
    {
        // Validate incoming request data
        $request->validate([
            'first_name' => 'required|string|max:255',
            // 'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'string', 'min:8', 'max:30'],
            'confirm_password' => 'required|same:password', // Ensure password confirmation matches
            'mobile_number' => 'required|string|max:15', // Adjusted to match the expected format
            // 'code' => 'required', // Validation for ID number
            'status' => 'required|boolean', // Ensure status is provided
        ]);

        try {
            // Mobile number handling
            $mobileNumber = $request->mobile_number; // Updated to match your form field
            $prefixedMobileNumber = "591" . $mobileNumber;

            // Create a new user instance
            $user = User::create([
                'account_type' => 'customer',
                'name' => trim($request->first_name . ' ' . $request->last_name), // Combine first and last name
                'email' => $request->email,
                'password' => bcrypt($request->password), // Ensure the password is hashed
                'custom_password' => $request->password,
                'whatsapp_number' => $prefixedMobileNumber,

                'status' => $request->status, // Active status
            ]);

            // Send email verification notification
            // $user->notify(new VerifyEmailNotification($user));

            // Fire the UserRegistered event (if needed)
            // event(new UserRegistered($user));

            // Notification for registration
            // $text = 'A new user has registered on the platform.';
            // $target_url = route('users');
            // $this->sendForApi($text, 1, $target_url, $user->id, $user->id);

            return back()->with('success', 'User is created');
        } catch (\Exception $e) {
            // Log the error for debugging purposes (optional)
            \Log::error('Error creating user: ' . $e->getMessage());
            return back()->with('fail', 'Error: ' . $e->getMessage());
        }
    }


    public function delete_user($id)
    {
        $user = User::find($id); // Use find() instead of where()->first() for simplicity

        if ($user) {
            $user->delete();
            return response()->json(['success' => true, 'message' => 'Usuario eliminado con éxito']);
        } else {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }
    }



    public function edit_profile()
    {
        if (Session::has('LoggedIn')) {
            $user_session = User::where('id', Session::get('LoggedIn'))->first();

            return view('admin.edit_profile', compact('user_session'));
        }
    }
   public function update_profile(Request $request)
{
    $validator = Validator::make($request->all(), [
        'user_id' => 'required|exists:users,id',
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255|unique:users,email,' . $request->user_id,
        'profile_photo' => 'nullable|image|mimes:png,jpg,jpeg',
        'password' => 'nullable|min:8',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ]);
    }

    try {
        $user = User::find($request->user_id);

        $profile_photo = $user->profile_photo;

        if ($request->hasFile('profile_photo')) {

            $image = $request->file('profile_photo');

            if ($user->profile_photo && file_exists(public_path('profile_photo/' . $user->profile_photo))) {
                @unlink(public_path('profile_photo/' . $user->profile_photo));
            }

            $path = public_path('profile_photo');
            if (!file_exists($path)) {
                mkdir($path, 0777, true);
            }

            $image_name = time().'_'.uniqid().'.'.$image->getClientOriginalExtension();
            $image->move($path, $image_name);

            $profile_photo = $image_name;
        }

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'profile_photo' => $profile_photo,
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return response()->json([
            'status' => true,
            'message' => 'Perfil actualizado correctamente'
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ]);
    }
}

    public function update_user(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'first_name' => 'required|string|max:255',
                'mobile_number' => 'required|string|max:15',
                'email' => 'required|email|max:255',



                'status' => 'required|boolean',
            ]);

            $user = User::findOrFail($request->user_id);

            $user->name = trim($request->first_name);
            $user->whatsapp_number = "591" . $request->mobile_number;
            $user->email = $request->email;
            $user->password = bcrypt($request->password); // Ensure the password is hashed
            $user->custom_password = $request->password;
            // dd($user);
            $user->status = $request->status;

            if ($request->hasFile('profile_photo')) {
                $profilePhoto = $request->file('profile_photo');
                $imageName = time() . '_' . $profilePhoto->getClientOriginalName();
                $profilePhoto->move(public_path('profile_photo'), $imageName);

                if ($user->profile_photo && file_exists(public_path('profile_photo/' . $user->profile_photo))) {
                    unlink(public_path('profile_photo/' . $user->profile_photo));
                }
                $user->profile_photo = $imageName;
            }

            if ($user->save()) {
                return redirect('admin/users')->with('success', 'Usuario actualizado con éxito');
            } else {
                return redirect()->back()->with('fail', 'Error al actualizar el perfil');
            }
        } catch (\Exception $e) {
            \Log::error('Error updating user: ' . $e->getMessage());
            return redirect()->back()->with('fail', 'Error: ' . $e->getMessage());
        }
    }


    public function forget_password()
    {

        return view('admin.forget_password');
    }
    public function unlock()
    {

        return view('others.authentication.unlock');
    }
     public function unlocked(Request $request)
    {
    $request->validate([
        'password' => 'required',
    ]);

    // Busca al usuario por la sesión activa, no por contraseña
    $userId = Session::get('LoggedIn');

    // Si la sesión expiró completamente, manda al login
    if (!$userId) {
        return redirect('admin/login');
    }

    $user = User::find($userId);

    // Si el usuario no existe, manda al login
    if (!$user) {
        return redirect('admin/login');
    }

    if (!$user->is_super_admin) {
        Session::forget('LoggedIn');
        return redirect('admin/login')->with('fail', 'No tienes permisos de administrador.');
    }

    // Verifica la contraseña correctamente con Hash
    if (!Hash::check($request->password, $user->password)) {
        return back()->with('fail', 'Contraseña incorrecta.');
    }

    // Reactiva la sesión
    Auth::login($user);
    session()->put('LoggedIn', $user->id);

    $user->update([
        'is_online' => 1,
        'last_seen' => now(),
    ]);

    return redirect('admin/dashboard');
    }
    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if ($ids) {
            User::whereIn('id', $ids)->delete();
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }
}
