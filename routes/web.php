<?php

use App\Http\Controllers\Admin\AboutUsController;
use App\Http\Controllers\Admin\WhatsAppBotController;

use App\Http\Controllers\Admin\Admin;

use App\Http\Controllers\Admin\AdminWithdrawalController;

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BankController;
use App\Http\Controllers\Admin\BannerController;

use App\Http\Controllers\Admin\WalletController;
use App\Http\Controllers\Admin\WalletTransactionController;
use App\Http\Controllers\Admin\TopUpController;
use App\Http\Controllers\Admin\BlogCategoryController;

use App\Http\Controllers\Admin\BlogController;

use App\Http\Controllers\Admin\HistoryController;
use App\Http\Controllers\Admin\CategoryController;



use App\Http\Controllers\Admin\ContactUsController;



use App\Http\Controllers\Admin\CurrencyController;

use App\Http\Controllers\Admin\DriverController;

use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\NewsController;

use App\Http\Controllers\Admin\NotificationSettingController;

use App\Http\Controllers\Admin\ProofOfDeliveryController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\Admin\PortfolioController;
use App\Http\Controllers\Admin\PricingRuleController;
use App\Http\Controllers\Admin\QRCodeController;

use App\Http\Controllers\Admin\ReferralSettingController;
use App\Http\Controllers\Admin\ResetPasswordController;

use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SubcategoryController;


use App\Http\Controllers\Admin\SupportTicketController;
use App\Http\Controllers\Admin\TagController;


use App\Http\Controllers\Admin\TestimonialController;
use App\Http\Controllers\Admin\TripController;
use App\Http\Controllers\Admin\VehicleController;
use App\Http\Controllers\Admin\ServiceRateController;
use App\Http\Controllers\Admin\AssistantsController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\SetLocale;
use App\Models\Language;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;















// Real-time market chart page (Inertia + Vue)
// Instrument list













/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Catch-All Route for Non-Existent Pages
Route::fallback(function () {
    return response()->view('others.error_pages.error_page5', [], 404);
});


//admin unique layouts

Route::view('dashboard-light', 'admin_unique_layout.light_dashboard')->name('dashboard-light');
Route::view('e-commerce-light', 'admin_unique_layout.e-commerce-light')->name('e-commerce-light');
Route::view('general-widget-light', 'admin_unique_layout.general-widget-light')->name('general-widget-light');
Route::view('dashboard-dark', 'admin_unique_layout.dark_dashboard')->name('dashboard-dark');
Route::view('e-commerce-dark', 'admin_unique_layout.e-commerce-dark')->name('e-commerce-dark');
Route::view('general-widget-dark', 'admin_unique_layout.general-widget-dark')->name('general-widget-dark');
Route::view('dashboard-box', 'admin_unique_layout.box_dashboard')->name('dashboard-box');
Route::view('e-commerce-box', 'admin_unique_layout.e-commerce-box')->name('e-commerce-box');
Route::view('general-widget-box', 'admin_unique_layout.general-widget-box')->name('general-widget-box');


//widgets
Route::view('general-widget', 'Widgets.general_widget')->name('general_widget');
Route::view('chart-widget', 'Widgets.chart_widget')->name('chart_widget');

//charts
Route::view('chart-apex', 'charts.chart_apex')->name('chart_apex');
Route::view('chart-sparkline', 'charts.chart_sparkline')->name('chart_sparkline');
Route::view('chart-flot', 'charts.chart_flot')->name('chart_flot');
Route::view('chart-knob', 'charts.chart_knob')->name('chart_knob');
Route::view('chart-morris', 'charts.chart_morris')->name('chart_morris');
Route::view('chartjs', 'charts.chartjs')->name('chartjs');
Route::view('chartist', 'charts.chartist')->name('chartist');
Route::view('chart-peity', 'charts.chart_peity')->name('chart_peity');

//landing_page
Route::view('landing-page', 'pages.landing_page')->name('landing_page');

Route::get('/error/{code}', function ($code) {
    abort($code);
});

// ── RASTREO PÚBLICO ─────────────────────────────────────────────────────────
// Cualquier persona con el link puede ver la ubicación del conductor en tiempo real
Route::get('/track/{token}', [\App\Http\Controllers\TrackingController::class, 'show'])
    ->name('tracking.show');

Route::get('/track/{token}/ping', [\App\Http\Controllers\TrackingController::class, 'ping'])
    ->name('tracking.ping');

Route::get('/verify-email/{id}', function ($id) {

    $user = User::findOrFail($id);

    // Prevent using the link twice
    if ($user->hasVerifiedEmail()) {
        return redirect('/dashboard');
    }

    // Mark verified + set online (exactly like your login)
    $user->update([
        'email_verified_at' => now(),
        'is_online'         => 1,
        'last_seen'         => Carbon::now('UTC'),
    ]);

    // Login user
    Auth::login($user);
    Session::put('LoggedIn', $user->id);
    Session::put('user_session', $user);
    Session::regenerate();

    return redirect('/dashboard')
        ->with('status', 'Welcome! Your email is verified.')
        ->with('verified', true);
})->name('verify.email.simple')->middleware('signed');

Route::post('/log', [UserController::class, 'login'])->name('login');
Route::group(['middleware' => ['prevent-back-history', SetLocale::class]], function () {
    Route::get('/', [UserController::class, 'home'])->name('home');

Route::get('/index', [UserController::class, 'home'])->name('index');

Route::get('/home', [UserController::class, 'home'])
    ->name('user.home')
    ->middleware('isLoggedIn');
    Route::get('/local/{ln}', function ($ln) {
        return redirect()->back()->with('local', $ln);
    });

    Route::get('/genTerm', [UserController::class, 'genTerm'])->name('genTerm');
    Route::get('/privacy', [UserController::class, 'privacy'])->name('privacy');
    Route::get('/book', [UserController::class, 'book'])->name('book');

    Route::get('newsDetails/{id}', [UserController::class, 'newsDetails'])->name('newsDetails');

    Route::get('/dashboard', [UserController::class, 'dashboard'])->name('dashboard')->middleware('isLoggedIn');
    Route::get('/overview', [UserController::class, 'overview'])->name('overview')->middleware('isLoggedIn');


    Route::get('/withdraw-history', [UserController::class, 'withdrawHistory'])->name('withdraw.history')->middleware('isLoggedIn');


    Route::post('/withdraw/request', [UserController::class, 'storeRequest'])
        ->name('withdraw.store');
    Route::get('/support', [UserController::class, 'support'])->name('support')->middleware('isLoggedIn');
    Route::get('/dashboard-data', [UserController::class, 'getDashboardData'])->name('getDashboardData');





    Route::get('blog', [UserController::class, 'blogs'])->name('blog');

    Route::get('service', [UserController::class, 'service'])->name('service');

    Route::post('/comments/store', [UserController::class, 'Commentstore'])->name('comments.store');
    Route::post('/reactions', [UserController::class, 'Reactionstore']);
    Route::get('/dashboard', [UserController::class, 'dashboard'])->name('dashboard')->middleware('isLoggedIn');
    Route::get('/logout', [UserController::class, 'logout'])->name('logout');

    Route::get('/blog_detail/{slug}', [UserController::class, 'blog_detail'])->name('blog.detail');
    Route::post('/blog/{id}/like', [BlogController::class, 'incrementLike'])->name('blog.like');
    Route::post('blog-comment', [UserController::class, 'blogCommentStore'])->name('blog-comment.store');
    Route::post('blog-comment-reply', [UserController::class, 'blogCommentReplyStore'])->name('blog-comment-reply.store');
    Route::get('search-blog-list', [UserController::class, 'searchBlogList'])->name('search-blog.list');
    Route::get('/signup', [UserController::class, 'signup'])->name('signup');
    Route::post('/send-otp', [UserController::class, 'sendOtp'])->name('send.otp');
    Route::post('/verify-otp', [UserController::class, 'verifyOtp'])->name('verify.otp');
    Route::get('Userlogin', [UserController::class, 'Userlogin'])->name('Userlogin');
    Route::post('/send-login-otp', [UserController::class, 'sendLoginOtp']);
    Route::post('/verify-login-otp', [UserController::class, 'verifyLoginOtp']);

    Route::get('create', [SupportTicketController::class, 'create'])->name('tickets.create');

    Route::get('show/{uuid}', [SupportTicketController::class, 'ticketUserShow'])->name('show')->middleware('isLoggedIn');
    Route::post('store', [SupportTicketController::class, 'store'])->name('tickets.store');
    Route::post('/reg', [UserController::class, 'registration'])->name('register');
    Route::get('/contact', [UserController::class, 'contact'])->name('contact');
    Route::get('/about', [UserController::class, 'about'])->name('about');
    Route::get('/workUs', [UserController::class, 'workUs'])->name('workUs');
    Route::get('/faq', [UserController::class, 'faq'])->name('faq');
    Route::get('/userNotifications', [UserController::class, 'userNotifications'])->name('userNotifications');
   


    Route::get('/faq', [UserController::class, 'faqs'])->name('faqs');
    Route::get('/membership', [UserController::class, 'membership'])->name('membership');

    Route::get('/vacancy ', [UserController::class, 'vacancy'])->name('vacancy');

    Route::get('/gen_cards', [UserController::class, 'gen_cards'])->name('gen_cards')->middleware('isLoggedIn');
    Route::get('/gen_members_area', [UserController::class, 'gen_members_area'])->name('gen_members_area')->middleware('isLoggedIn');
    Route::get('/partners', [UserController::class, 'partners'])->name('partners')->middleware('isLoggedIn');
    Route::get('/events', [UserController::class, 'events'])->name('events')->middleware('isLoggedIn');
    Route::get('/verification', [UserController::class, 'verification'])->name('verification')->middleware('isLoggedIn');
   
});







Route::post('/forget_mail', [UserController::class, 'forget_mail'])->name('forget_mail');
Route::post('/sendResetPasswordLink', [UserController::class, 'sendResetPasswordLink'])->name('sendResetPasswordLink');
Route::get('password/reset/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
Route::post('password/reset', [ResetPasswordController::class, 'reset'])->name('password.update');
Route::get('/ResetPasswordLoad', [UserController::class, 'ResetPasswordLoad'])->name('ResetPasswordLoad');
Route::post('/ResetPassword', [UserController::class, 'ResetPassword'])->name('ResetPassword');

Route::get('admin/unlock', fn() => redirect('admin/login'))->name('unlock');
Route::post('/update-mode', [Admin::class, 'updateMode']);
Route::get('/get-user-mode', [Admin::class, 'getUserMode'])->name('getUserMode');

Route::group(['prefix' => 'admin', 'middleware' => ['check.session', 'AdminIsLoggedIn']], function () {

    Route::group(['middleware' => 'admin-prevent-back-history', SetLocale::class], function () {

        // Driver Management
        Route::get('/drivers', [DriverController::class, 'index'])->name('drivers.index');
        Route::get('/drivers/pending', [DriverController::class, 'listPending'])->name('drivers.pending');
        Route::get('/drivers/create', [DriverController::class, 'create'])->name('drivers.create');
        Route::post('/drivers', [DriverController::class, 'store'])->name('drivers.store');
        Route::get('/drivers/{driver}', [DriverController::class, 'show'])->name('drivers.show');
        Route::get('/drivers/{driver}/edit', [DriverController::class, 'edit'])->name('drivers.edit');
        Route::put('/drivers/{driver}', [DriverController::class, 'update'])->name('drivers.update');
        Route::delete('/drivers/{driver}', [DriverController::class, 'destroy'])->name('drivers.destroy');
        Route::post('/drivers/bulk-delete', [DriverController::class, 'bulkDelete'])->name('drivers.bulk.delete');

        // Verification APIs
        Route::post('/drivers/{id}/verify', [DriverController::class, 'verifyDriver'])->name('drivers.verify');
        Route::post('/drivers/{id}/reject', [DriverController::class, 'rejectDriver'])->name('drivers.reject');
        Route::post('/drivers/{id}/suspend', [DriverController::class, 'suspendDriver'])->name('drivers.suspend');

        // Document Management
        Route::post('/drivers/{driverId}/documents', [DriverController::class, 'uploadDocument'])->name('drivers.documents.upload');
        Route::post('/documents/{id}/verify', [DriverController::class, 'verifyDocument'])->name('documents.verify');
        Route::post('/documents/{id}/reject', [DriverController::class, 'rejectDocument'])->name('documents.reject');
        Route::delete('/documents/{id}', [DriverController::class, 'deleteDocument'])->name('documents.delete');
        Route::get('/documents/{id}/preview', [DriverController::class, 'previewDocument'])->name('documents.preview');
        Route::get('/documents/{id}/download', [DriverController::class, 'downloadDocument'])->name('documents.download');

        // Vehicles
        Route::resource('vehicles', VehicleController::class)->except(['show']);
        Route::post('vehicles/bulk-delete', [VehicleController::class, 'bulkDelete'])->name('vehicles.bulk.delete');

        // Trips
        Route::prefix('trips')->name('trips.')->group(function () {
            Route::get('/', [TripController::class, 'index'])->name('index');
            Route::get('/create', [TripController::class, 'create'])->name('create');
            Route::post('/', [TripController::class, 'store'])->name('store');
            Route::get('/{trip}/edit', [TripController::class, 'edit'])->name('edit');
            Route::put('/{trip}', [TripController::class, 'update'])->name('update');
            Route::delete('/{trip}', [TripController::class, 'destroy'])->name('destroy');
            Route::post('/bulk-delete', [TripController::class, 'bulkDelete'])->name('bulk.delete');

            // Manual Assignment page
            Route::get('/manual-assignment', [TripController::class, 'manualAssignment'])->name('manual-assignment');

            // Fleet overview
            Route::get('/fleet', [TripController::class, 'fleet'])->name('fleet');

            // Tracking token (genera o devuelve el token público del viaje)
            Route::post('/{tripId}/tracking-token', [\App\Http\Controllers\TrackingController::class, 'generateToken'])
                ->name('tracking.token');
        });

        // Pricing Rules
        Route::resource('pricing-rules', PricingRuleController::class)->except(['show']);
        Route::post('pricing-rules/bulk-delete', [PricingRuleController::class, 'bulkDelete'])->name('pricing-rules.bulk.delete');

        // Service Rates (per-service pricing & commission) — admin only
        Route::get('/service-rates', [ServiceRateController::class, 'index'])->name('admin.service-rates.index');
        Route::post('/service-rates/commission', [ServiceRateController::class, 'updateCommission'])->name('admin.service-rates.commission');
        Route::put('/service-rates/{serviceRate}', [ServiceRateController::class, 'update'])->name('admin.service-rates.update');

        // Assistants Management — admin only
        Route::get('/assistants', [AssistantsController::class, 'index'])->name('admin.assistants.index');
        Route::post('/assistants', [AssistantsController::class, 'store'])->name('admin.assistants.store');
        Route::delete('/assistants/{user}', [AssistantsController::class, 'destroy'])->name('admin.assistants.destroy');
        Route::post('/assistants/{user}/permissions', [AssistantsController::class, 'updatePermissions'])->name('admin.assistants.permissions');

        // Audit Logs (read-only mostly)
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
        
        // Wallet Management
        Route::get('/wallets', [WalletController::class, 'index'])->name('wallets.index');
        Route::get('/wallets/{wallet}', [WalletController::class, 'show'])->name('wallets.show');
        Route::put('/wallets/{wallet}/toggle-block', [WalletController::class, 'toggleBlock'])->name('wallets.toggle-block');
        Route::post('/wallets/{wallet}/adjust-balance', [WalletController::class, 'adjustBalance'])->name('wallets.adjust-balance');

        // TopUp Requests Management
        Route::get('/topup-requests', [TopUpController::class, 'index'])->name('topup-requests.index');
        Route::get('/topup-requests/{topUpRequest}', [TopUpController::class, 'show'])->name('topup-requests.show');
        Route::post('/topup-requests/{topUpRequest}/approve', [TopUpController::class, 'approve'])->name('topup-requests.approve');
        Route::post('/topup-requests/{topUpRequest}/reject', [TopUpController::class, 'reject'])->name('topup-requests.reject');

        // Wallet Transactions
        Route::get('/wallet-transactions', [WalletTransactionController::class, 'index'])->name('wallet-transactions.index');
        Route::get('/wallet-transactions/export', [WalletTransactionController::class, 'export'])->name('wallet-transactions.export');

// WhatsApp Bot — conversaciones y mensajes
        Route::prefix('whatsapp')->name('whatsapp.')->group(function () {
            Route::get('/',                      [WhatsAppBotController::class, 'index'])->name('index');
            Route::get('/live-stats',            [WhatsAppBotController::class, 'liveStats'])->name('live-stats');
            Route::get('/{id}',                  [WhatsAppBotController::class, 'show'])->name('show');
            Route::get('/{id}/messages',         [WhatsAppBotController::class, 'liveMessages'])->name('live-messages');
            Route::post('/{id}/send',            [WhatsAppBotController::class, 'sendMessage'])->name('send');
            Route::post('/{id}/toggle-escalate', [WhatsAppBotController::class, 'toggleEscalate'])->name('toggle-escalate');
            Route::delete('/{id}',               [WhatsAppBotController::class, 'destroy'])->name('destroy');
        });

        // Proof of Delivery Routes
        Route::prefix('proof-of-delivery')->name('proof-of-delivery.')->group(function () {
            Route::get('/', [ProofOfDeliveryController::class, 'index'])->name('index');
            Route::get('/{id}', [ProofOfDeliveryController::class, 'show'])->name('show');
            Route::get('/trip/{tripId}', [ProofOfDeliveryController::class, 'showByTrip'])->name('show-by-trip');
            Route::get('/{id}/download-pdf', [ProofOfDeliveryController::class, 'downloadPdf'])->name('download-pdf');
            Route::delete('/{id}', [ProofOfDeliveryController::class, 'destroy'])->name('destroy');
            Route::get('/export/csv', [ProofOfDeliveryController::class, 'export'])->name('export');
        });
        
        Route::get('/withdrawals', [AdminWithdrawalController::class, 'index'])
            ->name('withdrawals.index');

        Route::post('/withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve'])
            ->name('withdrawals.approve');

        Route::post('/withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject'])
            ->name('withdrawals.reject');

        Route::post('/withdrawals/{withdrawal}/process', [AdminWithdrawalController::class, 'process'])
            ->name('withdrawals.process');


        Route::get('/referral-settings', [ReferralSettingController::class, 'index'])
            ->name('admin.referral.settings');

        Route::put('/referral-settings', [ReferralSettingController::class, 'update'])
            ->name('admin.referral.settings.update');

            // History Routes
    Route::get('/login-history', [HistoryController::class, 'loginHistory'])->name('login.history');
    Route::get('/notification-history', [HistoryController::class, 'notificationHistory'])->name('notification.history');
    Route::get('/transaction-history', [HistoryController::class, 'transactionHistory'])->name('transaction.history');

    // Optional: Export routes
    Route::get('/login-history/export', [HistoryController::class, 'exportLoginHistory'])->name('login.history.export');
    Route::get('/transaction-history/export', [HistoryController::class, 'exportTransactionHistory'])->name('transaction.history.export');
        Route::get('/notification-settings', [NotificationSettingController::class, 'index'])
            ->name('admin.notification.settings');

        Route::put('/notification-settings', [NotificationSettingController::class, 'update'])
            ->name('admin.notification.update');


        // Notification Templates - Index Page
        Route::get('/notification-templates', [NotificationTemplateController::class, 'index'])
            ->name('notification.template.index');

        // Edit Template Page
        Route::get('/notification-template/{id}/edit', [NotificationTemplateController::class, 'edit'])
            ->name('notification.template.edit');

        // Update Template
        Route::put('/notification-template/{id}', [NotificationTemplateController::class, 'update'])
            ->name('notification.template.update');

        // AJAX Toggle (Email/SMS/Push Status)
        Route::post('/notification-template/toggle', [NotificationTemplateController::class, 'toggle'])
            ->name('notification.template.toggle');




        Route::get('balance-management', [Admin::class, 'balanceManagement'])->name('balance.management');
        Route::post('add-balance', [Admin::class, 'addBalance'])->name('admin.add.balance');
        Route::get('balance-history', [Admin::class, 'getBalanceHistory'])->name('admin.balance.history');

        Route::get('/qrcode', [QRCodeController::class, 'index'])->name('qrcode.index')->middleware('AdminIsLoggedIn');
        Route::get('/destroy_qrcode/{id}', [QRCodeController::class, 'destroy'])->name('destroy');
        Route::post('/qrcode/generate', [QRCodeController::class, 'generateQrCode'])->name('qrcode.generate');
        Route::get('/qrcode/download/{data}', [QRCodeController::class, 'downloadQrCode'])->name('qrcode.download');

        // Testimonial routes
        Route::prefix('testimonials')->group(function () {
            Route::get('/', [TestimonialController::class, 'index'])->name('testimonials.index');
            Route::get('create', [TestimonialController::class, 'create'])->name('testimonials.create');
            Route::post('store', [TestimonialController::class, 'store'])->name('testimonials.store');
            Route::get('edit/{id}', [TestimonialController::class, 'edit'])->name('testimonials.edit');
            Route::post('update/{id}', [TestimonialController::class, 'update'])->name('testimonials.update');
            Route::delete('delete/{id}', [TestimonialController::class, 'destroy'])->name('testimonials.delete');
            Route::post('bulk-delete', [TestimonialController::class, 'bulkDelete'])->name('testimonials.bulk.delete');
        });

        // Portfolio routes
        Route::prefix('portfolios')->group(function () {
            Route::get('/', [PortfolioController::class, 'index'])->name('portfolios.index');
            Route::get('create', [PortfolioController::class, 'create'])->name('portfolios.create');
            Route::post('store', [PortfolioController::class, 'store'])->name('portfolios.store');
            Route::get('edit/{id}', [PortfolioController::class, 'edit'])->name('portfolios.edit');
            Route::post('update/{id}', [PortfolioController::class, 'update'])->name('portfolios.update');
            Route::delete('delete/{id}', [PortfolioController::class, 'destroy'])->name('portfolios.delete');
            Route::post('bulk-delete', [PortfolioController::class, 'bulkDelete'])->name('portfolios.bulk.delete');
        });

        Route::get('/get/messages', [Admin::class, 'getMessages'])->name('get.messages');

        Route::get('/get-notifications', [Admin::class, 'getNotifications'])->name('get.notifications');
        
        //icons
        Route::get('flag-icon', [Admin::class, 'flag_icon'])->name('flag_icon');
        Route::get('font-awesome', [Admin::class, 'font_awesome'])->name('font_awesome');
        Route::get('ico-icon', [Admin::class, 'ico_icon'])->name('ico_icon');
        Route::get('themify-icon', [Admin::class, 'themify_icon'])->name('themify_icon');
        Route::get('feather-icon', [Admin::class, 'feather_icon'])->name('feather_icon');
        Route::get('whether-icon', [Admin::class, 'whether_icon'])->name('whether_icon');

        Route::resource('banners', BannerController::class)->names('admin.banners');
        Route::post('banners/bulk-destroy', [BannerController::class, 'bulkDestroy'])->name('admin.banners.bulkDestroy');

        Route::get('/local/{ln}', function ($ln) {
            $language = Language::where('iso_code', $ln)->first();
            if (!$language) {
                $language = Language::where('default_language', 'on')->first();
                if (!$language) {
                    $language = Language::find(1);
                }
                $ln = $language->iso_code;
            }

            session(['local' => $ln]);
            App::setLocale($ln);
            return redirect()->back();
        });

        //file manager

        Route::get('/file-manager', [Admin::class, 'file_manager'])->name('file_manager');
        Route::post('/create-folder', [Admin::class, 'createFolder'])->name('create.folder');
        Route::post('/upload-file/{folderId}', [Admin::class, 'upload'])->name('upload.file');



        Route::group(['prefix' => 'support-ticket', 'as' => 'support-ticket.'], function () {
            Route::get('index', [SupportTicketController::class, 'ticketIndex'])->name('index');
            Route::get('open', [SupportTicketController::class, 'ticketOpen'])->name('open');
            Route::get('show/{uuid}', [SupportTicketController::class, 'ticketShow'])->name('show')->middleware('AdminIsLoggedIn');
            Route::get('delete/{uuid}', [SupportTicketController::class, 'ticketDelete'])->name('delete');
            Route::post('change-ticket-status', [SupportTicketController::class, 'changeTicketStatus'])->name('changeTicketStatus');
            Route::post('message-store', [SupportTicketController::class, 'messageStore'])->name('messageStore');
            Route::post('bulk-delete', [SupportTicketController::class, 'bulkDelete'])->name('bulkDelete');
        });




        Route::group(['prefix' => 'news', 'as' => 'news.'], function () {
            Route::get('', [NewsController::class, 'index'])->name('index');
            Route::get('create', [NewsController::class, 'create'])->name('create'); // This matches 'audiolibros.crear'
            Route::post('store', [NewsController::class, 'store'])->name('store');
            Route::get('edit/{id}', [NewsController::class, 'edit'])->name('edit'); // This matches 'audiolibros.edit'
            Route::post('update/{id}', [NewsController::class, 'update'])->name('update');
            Route::delete('/destroy/{news}', [NewsController::class, 'destroy'])->name('destroy');

            Route::post('/eliminar-multiple', [NewsController::class, 'bulkDelete'])->name('bulk-delete');
        });

        Route::group(['prefix' => 'settings', 'as' => 'settings.'], function () {
            //Start:: General Settings
            Route::get('general-settings', [SettingController::class, 'GeneralSetting'])->name('general_setting');
            Route::post('general-settings-update', [SettingController::class, 'GeneralSettingUpdate'])->name('general_setting.cms.update');

            Route::get('metas', [SettingController::class, 'metaIndex'])->name('meta.index');
            Route::get('meta/edit/{uuid}', [SettingController::class, 'editMeta'])->name('meta.edit');
            Route::post('meta/update/{uuid}', [SettingController::class, 'updateMeta'])->name('meta.update');

            // Route::get('site-share-content', [SettingController::class, 'siteShareContent'])->name('site-share-content');
            Route::get('map-api-key', [SettingController::class, 'mapApiKey'])->name('map-api-key');



            //Start:: Social Login Settings
            Route::get('social-login-settings', [SettingController::class, 'socialLoginSettings'])->name('social-login-settings');
            Route::post('social-settings-update', [SettingController::class, 'socialLoginSettingsUpdate'])->name('social-login-settings.update');



            //Start:: Currency Settings
            Route::group(['prefix' => 'currency', 'as' => 'currency.'], function () {
                Route::get('', [CurrencyController::class, 'index'])->name('index');
                Route::post('currency', [CurrencyController::class, 'store'])->name('store');
                Route::get('edit/{id}/{slug?}', [CurrencyController::class, 'edit'])->name('edit');
                Route::patch('update/{id}', [CurrencyController::class, 'update'])->name('update');
                Route::get('delete/{id}', [CurrencyController::class, 'delete'])->name('delete');
            });




            //Start:: Mail Config
            Route::get('mail-configuration', [SettingController::class, 'mailConfiguration'])->name('mail-configuration');
            Route::post('send-test-mail', [SettingController::class, 'sendTestMail'])->name('send.test.mail');
            Route::post('save-setting', [SettingController::class, 'saveSetting'])->name('save.setting');
            //End:: Mail Config



            //Start:: FAQ Question & Answer
            Route::get('faq-settings', [SettingController::class, 'faqCMS'])->name('faq.cms');
            Route::get('faq-tab', [SettingController::class, 'faqTab'])->name('faq.tab');
            Route::get('faq-question-settings', [SettingController::class, 'faqQuestion'])->name('faq.question');
            Route::post('faq-question-settings', [SettingController::class, 'faqQuestionUpdate'])->name('faq.question.update');
            //End:: FAQ Question & Answer



            //Start:: Support Ticket
            Route::group(['prefix' => 'support-ticket', 'as' => 'support-ticket.'], function () {
                Route::get('/', [SettingController::class, 'supportTicketCMS'])->name('cms');
                Route::get('question-answer', [SettingController::class, 'supportTicketQuesAns'])->name('question');
                Route::post('question-answer', [SettingController::class, 'supportTicketQuesAnsUpdate'])->name('question.update');
                Route::post('questionAnsDelete', [SettingController::class, 'questionAnsDelete'])->name('questionAnsDelete');

                Route::get('department', [SupportTicketController::class, 'Department'])->name('department');
                Route::post('department', [SupportTicketController::class, 'DepartmentStore'])->name('department.store');
                Route::delete('department-delete/{uuid}', [SupportTicketController::class, 'departmentDelete'])->name('department.delete');

                Route::get('priority', [SupportTicketController::class, 'Priority'])->name('priority');
                Route::post('priority', [SupportTicketController::class, 'PriorityStore'])->name('priority.store');
                Route::delete('priorities-delete/{uuid}', [SupportTicketController::class, 'priorityDelete'])->name('priority.delete');

                Route::get('services', [SupportTicketController::class, 'RelatedService'])->name('services');
                Route::post('services', [SupportTicketController::class, 'RelatedServiceStore'])->name('services.store');
                Route::delete('services-delete/{uuid}', [SupportTicketController::class, 'relatedServiceDelete'])->name('services.delete');
            });
            //End:: Support Ticket

            // Start:: Contact Us
            Route::get('contact-us-cms', [ContactUsController::class, 'contactUsCMS'])->name('contact.cms');
            // End:: Contact Us

            Route::get('payment-method', [SettingController::class, 'paymentMethod'])->name('payment_method_settings');

            //start:: Bank
            Route::group(['prefix' => 'bank'], function () {
                Route::get('/', [BankController::class, 'index'])->name('bank.index');
                Route::post('/store', [BankController::class, 'store'])->name('bank.store');
                Route::get('/edit/{id}', [BankController::class, 'edit'])->name('bank.edit');
                Route::patch('/update/{id}', [BankController::class, 'update'])->name('bank.update');
                Route::get('/status/{id}', [BankController::class, 'status'])->name('bank.status');
                Route::delete('delete/{id}', [BankController::class, 'delete'])->name('bank.delete');
            });

            // Start:: About Us
            Route::group(['prefix' => 'about', 'as' => 'about.'], function () {
                Route::get('about-us-gallery-area', [AboutUsController::class, 'galleryArea'])->name('gallery-area');
                Route::post('about-us-gallery-area', [AboutUsController::class, 'galleryAreaUpdate'])->name('gallery-area.update');
                Route::post('gallery/delete', [AboutUsController::class, 'deleteGalleryImage'])->name('gallery.delete');


                Route::get('about-us-our-history', [AboutUsController::class, 'ourHistory'])->name('our-history');
                Route::post('about-us-our-history', [AboutUsController::class, 'ourHistoryUpdate'])->name('our-history.update');

                Route::get('about-us-upgrade-skill', [AboutUsController::class, 'upgradeSkill'])->name('upgrade-skill');
                Route::post('about-us-upgrade-skill', [AboutUsController::class, 'upgradeSkillUpdate'])->name('upgrade-skill.update');
                Route::post('skillDelete', [AboutUsController::class, 'deleteSkill'])->name('skill.delete');


                Route::get('about-us-team-member', [AboutUsController::class, 'teamMember'])->name('team-member');
                Route::post('about-us-team-member', [AboutUsController::class, 'teamMemberUpdate'])->name('team-member.update');

                Route::get('about-us-instructor-support', [AboutUsController::class, 'instructorSupport'])->name('instructor-support');
                Route::post('about-us-instructor-support', [AboutUsController::class, 'instructorSupportUpdate'])->name('instructor-support.update');

                Route::get('about-us-client', [AboutUsController::class, 'client'])->name('client');
                Route::post('about-us-client', [AboutUsController::class, 'clientUpdate'])->name('client.update');
            });
            // End:: About Us

            Route::group(['prefix' => 'locations', 'as' => 'location.'], function () {
                Route::get('country', [LocationController::class, 'countryIndex'])->name('country.index');
                Route::post('country', [LocationController::class, 'countryStore'])->name('country.store');
                Route::get('country/{id}/{slug?}', [LocationController::class, 'countryEdit'])->name('country.edit');
                Route::patch('country/{id}', [LocationController::class, 'countryUpdate'])->name('country.update');
                Route::delete('country/delete/{id}', [LocationController::class, 'countryDelete'])->name('country.delete');

                Route::get('state', [LocationController::class, 'stateIndex'])->name('state.index');
                Route::post('state', [LocationController::class, 'stateStore'])->name('state.store');
                Route::get('state/{id}/{slug?}', [LocationController::class, 'stateEdit'])->name('state.edit');
                Route::patch('state/{id}', [LocationController::class, 'stateUpdate'])->name('state.update');
                Route::delete('state/delete/{id}', [LocationController::class, 'stateDelete'])->name('state.delete');

                Route::get('city', [LocationController::class, 'cityIndex'])->name('city.index');
                Route::post('city', [LocationController::class, 'cityStore'])->name('city.store');
                Route::get('city/{id}/{slug?}', [LocationController::class, 'cityEdit'])->name('city.edit');
                Route::patch('city/{id}', [LocationController::class, 'cityUpdate'])->name('city.update');
                Route::delete('city/delete/{id}', [LocationController::class, 'cityDelete'])->name('city.delete');
            });
        });
        Route::get('notification-url/{uuid}', [Admin::class, 'notificationUrl'])->name('notification.url');
        Route::post('mark-all-as-read', [Admin::class, 'markAllAsReadNotification'])->name('notification.all-read');
        Route::prefix('language')->group(function () {
            Route::get('/', [LanguageController::class, 'index'])->name('language.index')->middleware('AdminIsLoggedIn');
            Route::get('create', [LanguageController::class, 'create'])->name('language.create')->middleware('AdminIsLoggedIn');
            Route::post('store', [LanguageController::class, 'store'])->name('language.store');
            Route::get('edit/{id}/{iso_code?}', [LanguageController::class, 'edit'])->name('language.edit')->middleware('AdminIsLoggedIn');
            Route::post('update/{id}', [LanguageController::class, 'update'])->name('language.update');
            Route::get('translate/{id}', [LanguageController::class, 'translateLanguage'])->name('language.translate')->middleware('AdminIsLoggedIn');
            Route::post('update-translate/{id}', [LanguageController::class, 'updateTranslate'])->name('update.translate');
            Route::get('delete/{id}', [LanguageController::class, 'delete'])->name('language.delete');
            Route::post('import', [LanguageController::class, 'import'])->name('language.import');
            Route::post('update-language/{id}', [LanguageController::class, 'updateLanguage'])->name('update-language');
        });


        Route::prefix('tag')->group(function () {
            Route::get('/', [TagController::class, 'index'])->name('tag.index')->middleware('AdminIsLoggedIn');
            Route::get('create', [TagController::class, 'create'])->name('tag.create')->middleware('AdminIsLoggedIn');
            Route::post('store', [TagController::class, 'store'])->name('tag.store');
            Route::get('edit/{uuid}', [TagController::class, 'edit'])->name('tag.edit')->middleware('AdminIsLoggedIn');
            Route::patch('update/{uuid}', [TagController::class, 'update'])->name('tag.update');
            Route::get('delete/{uuid}', [TagController::class, 'delete'])->name('tag.delete');
        });
        Route::prefix('category')->group(function () {
            Route::get('/', [CategoryController::class, 'index'])->name('category.index')->middleware('AdminIsLoggedIn');
            Route::get('create', [CategoryController::class, 'create'])->name('category.create')->middleware('AdminIsLoggedIn');
            Route::post('store', [CategoryController::class, 'store'])->name('category.store');
            Route::get('edit/{id}', [CategoryController::class, 'edit'])->name('category.edit')->middleware('AdminIsLoggedIn');
            Route::post('update/{id}', [CategoryController::class, 'update'])->name('category.update');
            Route::delete('delete/{id}', [CategoryController::class, 'delete'])->name('category.delete');

            Route::post('bulk-delete', [CategoryController::class, 'bulkDelete'])->name('category.bulk.delete');
        });


        Route::prefix('subcategory')->group(function () {
            Route::get('/', [SubcategoryController::class, 'index'])->name('subcategory.index');
            Route::get('create', [SubcategoryController::class, 'create'])->name('subcategory.create');
            Route::post('store', [SubcategoryController::class, 'store'])->name('subcategory.store');
            Route::get('edit/{uuid}', [SubcategoryController::class, 'edit'])->name('subcategory.edit');
            Route::post('update/{uuid}', [SubcategoryController::class, 'update'])->name('subcategory.update');
            Route::delete('delete/{uuid}', [SubcategoryController::class, 'delete'])->name('subcategory.delete');
            Route::post('bulk-delete', [SubcategoryController::class, 'bulkDelete'])->name('subcategory.bulk.delete');
        });


        Route::get('childcategory', [SubcategoryController::class, 'childcategory'])->name('childcategory')->middleware('AdminIsLoggedIn');


        Route::group(['prefix' => 'blog', 'as' => 'blog.', 'middleware' => 'AdminIsLoggedIn'], function () {
            Route::get('/', [BlogController::class, 'index'])->name('index');
            Route::get('create', [BlogController::class, 'create'])->name('create');
            Route::post('store', [BlogController::class, 'store'])->name('store');
            Route::get('edit/{uuid}', [BlogController::class, 'edit'])->name('edit');
            Route::post('update/{uuid}', [BlogController::class, 'update'])->name('update'); // Now matches method signature
            Route::get('delete/{uuid}', [BlogController::class, 'delete'])->name('delete');
            Route::get('blog-comment-list', [BlogController::class, 'blogCommentList'])->name('blog-comment-list')->middleware('AdminIsLoggedIn');
            Route::post('change-blog-comment-status', [BlogController::class, 'changeBlogCommentStatus'])->name('changeBlogCommentStatus');
            Route::get('blog-comment-delete/{id}', [BlogController::class, 'blogCommentDelete'])->name('blogComment.delete');
            Route::post('blog-comment-bulk-delete', [BlogController::class, 'bulkDeleteComments'])->name('blogComment.bulkDelete'); // Unique name for blog comment bulk delete
            Route::post('bulk-delete', [BlogController::class, 'bulkDelete'])->name('bulkDelete'); // Fixed bulk delete route

            Route::get('blog-category-index', [BlogCategoryController::class, 'index'])->name('blog-category.index')->middleware('AdminIsLoggedIn');
            Route::post('blog-category-store', [BlogCategoryController::class, 'store'])->name('blog-category.store');
            Route::patch('blog-category-update/{uuid}', [BlogCategoryController::class, 'update'])->name('blog-category.update');
            Route::get('blog-category-delete/{uuid}', [BlogCategoryController::class, 'delete'])->name('blog-category.delete');
            Route::post('blog-category-bulk-delete', [BlogCategoryController::class, 'bulkDeleteBlogCategory'])->name('bulkDeleteBlogCategory');
        });


        Route::get('dashboard', [Admin::class, 'dashboard'])->name('dashboard');
        Route::get('dashboard/stats', [Admin::class, 'getRealtimeStats'])->name('dashboard.stats');
        Route::get('dashboard/chart-data', [Admin::class, 'chartData'])->name('dashboard.chart-data');
        Route::get('/edit_profile', [Admin::class, 'edit_profile'])->name('edit_profile')->middleware('AdminIsLoggedIn');
        Route::post('update_profile', [Admin::class, 'update_profile'])->name('update_profile')->middleware('AdminIsLoggedIn');


        Route::get('/change_password', [Admin::class, 'change_password'])->name('change_password')->middleware('AdminIsLoggedIn');
        Route::post('/update_password', [Admin::class, 'update_password'])->name('update_password')->middleware('AdminIsLoggedIn');



        Route::get('users', [Admin::class, 'users'])->name('users')->middleware('AdminIsLoggedIn');

        Route::get('user/edit/{id}', [Admin::class, 'edit_user'])->name('edit_user')->middleware('AdminIsLoggedIn');
        Route::post('update_user', [Admin::class, 'update_user'])->name('update_user')->middleware('AdminIsLoggedIn');

        Route::get('add_user', [Admin::class, 'add_user'])->middleware('AdminIsLoggedIn');
        Route::post('save_user', [Admin::class, 'save_user'])->middleware('AdminIsLoggedIn');

        Route::get('user/delete_user/{id}', [Admin::class, 'delete_user']);
        Route::post('user/bulk_delete', [Admin::class, 'bulkDelete'])->name('user.bulkDelete');
    });
});
// Public routes (accessible without authentication)
Route::get('/admin/login', [Admin::class, 'admin'])->name('admin')->middleware('AdminAlreadyLoggedIn');
Route::get('/', [Admin::class, 'admin'])->name('admin')->middleware('AdminAlreadyLoggedIn');
Route::post('/admin/log', [Admin::class, 'login'])->name('admin.login.post');
Route::get('/admin/log', fn() => redirect('/admin/login'));
Route::get('/admin/forget_password', [Admin::class, 'forget_password'])->name('forget_password');
Route::post('/admin/unlocked', [Admin::class, 'unlocked'])->name('unlocked');
Route::get('/admin/signout', [Admin::class, 'logout'])->name('logout');
