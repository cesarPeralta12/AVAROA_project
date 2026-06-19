<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\MarkdownMail;
use App\Mail\TestMail;
use App\Models\Currency;
use App\Models\FaqQuestion;
use App\Models\Language;
use App\Models\MailTemplate;
use App\Models\Meta;
use App\Models\Setting;
use App\Models\SupportTicketQuestion;
use App\Models\User;
use App\Tools\Repositories\Crud;
use App\Traits\General;
use App\Traits\ImageSaveTrait;
use File;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;


class SettingController extends Controller
{
    use General, ImageSaveTrait;

    protected $metaModel;

    public function __construct(Meta $meta)
    {
        $this->metaModel = new Crud($meta);
    }

    public function GeneralSetting()
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = 'General Setting';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavGlobalSettingsActiveClass'] = 'mm-active';
            $data['generalSettingsActiveClass'] = 'active';
            $data['currencies'] = Currency::all();
            $data['settings'] = Setting::pluck('option_value', 'option_key')->toArray();
            $data['current_currency'] = Currency::where('current_currency', 'on')->first();
            $data['languages'] = Language::all();
            $data['default_language'] = Language::where('default_language', 'on')->first();

            return view('admin.application_settings.general.general-settings', $data);
        } else {
            return redirect('admin/login')->with('fail', 'Tu sesión expiró. Por favor, inicia sesión nuevamente.');
        }
    }

    public function GeneralSettingUpdate(Request $request)
    {
        $inputs = Arr::except($request->all(), ['_token']);
        $keys = [];

        foreach ($inputs as $k => $v) {
            $keys[$k] = $k;
        }

        foreach ($inputs as $key => $value) {
            $option = Setting::firstOrCreate(['option_key' => $key]);
            if ($request->hasFile('app_logo') && $key == 'app_logo') {
                $request->validate([
                    'app_logo' => 'mimes:png,svg,gif'
                ]);
                $this->deleteFile(get_option('app_logo'));
                $option->option_value = $this->saveImage('setting', $request->app_logo, null, null);
                $option->save();
            } elseif ($request->hasFile('app_black_logo') && $key == 'app_black_logo') {
                $request->validate([
                    'app_black_logo' => 'mimes:png,svg,gif'
                ]);
                $this->deleteFile(get_option('app_black_logo'));
                $option->option_value = $this->saveImage('setting', $request->app_black_logo, null, null);
                $option->save();
            } elseif ($request->hasFile('app_fav_icon') && $key == 'app_fav_icon') {
                $request->validate([
                    'app_fav_icon' => 'mimes:png,svg,gif'
                ]);
                $this->deleteFile(get_option('app_fav_icon'));
                $option->option_value = $this->saveImage('setting', $request->app_fav_icon, null, null);
                $option->save();
            } elseif ($request->hasFile('app_footer_payment_image') && $key == 'app_footer_payment_image') {
                $request->validate([
                    'app_footer_payment_image' => 'mimes:png,svg,gif'
                ]);
                $this->deleteFile(get_option('app_footer_payment_image'));
                $option->option_value = $this->saveImage('setting', $request->app_footer_payment_image, null, null);
                $option->save();
            } elseif ($request->hasFile('app_pwa_icon') && $key == 'app_pwa_icon') {
                $request->validate([
                    'app_pwa_icon' => 'mimes:png|dimensions:width=512,height=512'
                ]);
                $this->deleteFile(get_option('app_pwa_icon'));
                $option->option_value = $this->saveImage('setting', $request->app_pwa_icon, null, null);
                $option->save();
            } elseif ($request->hasFile('app_preloader') && $key == 'app_preloader') {
                $request->validate([
                    'app_preloader' => 'mimes:png,svg,gif'
                ]);
                $this->deleteFile(get_option('app_preloader'));
                $option->option_value = $this->saveImage('setting', $request->app_preloader, null, null);
                $option->save();
            } elseif ($request->hasFile('faq_image') && $key == 'faq_image') {
                $request->validate([
                    'faq_image' => 'mimes:png,jpg,jpeg|dimensions:min_width=650,min_height=650,max_width=650,max_height=650'
                ]);
                $this->deleteFile('faq_image');
                $option->option_value = $this->saveImage('setting', $request->faq_image, null, null);
                $option->save();
            } elseif ($request->hasFile('home_special_feature_first_logo') && $key == 'home_special_feature_first_logo') {
                $request->validate([
                    'home_special_feature_first_logo' => 'mimes:png|dimensions:min_width=77,min_height=77,max_width=77,max_height=77'
                ]);
                $this->deleteFile(get_option('home_special_feature_first_logo'));
                $option->option_value = $this->saveImage('setting', $request->home_special_feature_first_logo, null, null);
                $option->save();
            } elseif ($request->hasFile('home_special_feature_second_logo') && $key == 'home_special_feature_second_logo') {
                $request->validate([
                    'home_special_feature_second_logo' => 'mimes:png|dimensions:min_width=77,min_height=77,max_width=77,max_height=77'
                ]);
                $this->deleteFile(get_option('home_special_feature_second_logo'));
                $option->option_value = $this->saveImage('setting', $request->home_special_feature_second_logo, null, null);
                $option->save();
            } elseif ($request->hasFile('home_special_feature_third_logo') && $key == 'home_special_feature_third_logo') {
                $request->validate([
                    'home_special_feature_third_logo' => 'mimes:png|dimensions:min_width=77,min_height=77,max_width=77,max_height=77'
                ]);
                $this->deleteFile(get_option('home_special_feature_third_logo'));
                $option->option_value = $this->saveImage('setting', $request->home_special_feature_third_logo, null, null);
                $option->save();
            } elseif ($request->hasFile('sign_up_left_image') && $key == 'sign_up_left_image') {
                $request->validate([
                    'sign_up_left_image' => 'mimes:png,svg'
                ]);
                $this->deleteFile(get_option('sign_up_left_image'));
                $option->option_value = $this->saveImage('setting', $request->sign_up_left_image, null, null);
                $option->save();
            } elseif ($key == 'TIMEZONE' || $key == 'FORCE_HTTPS') {

                setEnvironmentValue($key, $value);

                $option->option_value = $value;
                $option->save();
            } else {
                $option->option_value = $value;
                $option->save();
            }
        }

        if ($request->currency_id) {
            Currency::where('id', $request->currency_id)->update(['current_currency' => 'on']);
            Currency::where('id', '!=', $request->currency_id)->update(['current_currency' => 'off']);
        }

        /**  ====== Set Language ====== */
        if ($request->language_id) {
            Language::where('id', $request->language_id)->update(['default_language' => 'on']);
            Language::where('id', '!=', $request->language_id)->update(['default_language' => 'off']);
            $language = Language::where('default_language', 'on')->first();
            if ($language) {
                $ln = $language->iso_code;
                session(['local' => $ln]);
                App::setLocale(session()->get('local'));
            }
        }


        Artisan::call('optimize:clear');



        return redirect()->back();
    }


    public function mapApiKey()
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = 'Map Api Key Setting';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavGlobalSettingsActiveClass'] = 'mm-active';
            $data['siteMapApiKeyActiveClass'] = 'active';
            return view('admin.application_settings.general.map-api-key', $data);
        }
    }






    public function socialLoginSettings()
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = 'Social Login Setting';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavGlobalSettingsActiveClass'] = 'mm-active';
            $data['socialLoginSettingsActiveClass'] = 'active';
            return view('admin.application_settings.general.social-login-settings', $data);
        }
    }

    public function socialLoginSettingsUpdate(Request $request)
    {
        $values['GOOGLE_LOGIN_STATUS'] = $request->GOOGLE_LOGIN_STATUS;
        $values['GOOGLE_CLIENT_ID'] = $request->GOOGLE_CLIENT_ID;
        $values['GOOGLE_CLIENT_SECRET'] = $request->GOOGLE_CLIENT_SECRET;
        $values['GOOGLE_REDIRECT_URL'] = $request->GOOGLE_REDIRECT_URL;

        $values['FACEBOOK_LOGIN_STATUS'] = $request->FACEBOOK_LOGIN_STATUS;
        $values['FACEBOOK_CLIENT_ID'] = $request->FACEBOOK_CLIENT_ID;
        $values['FACEBOOK_CLIENT_SECRET'] = $request->FACEBOOK_CLIENT_SECRET;
        $values['FACEBOOK_REDIRECT_URL'] = $request->FACEBOOK_REDIRECT_URL;

        $values['TWITTER_LOGIN_STATUS'] = $request->TWITTER_LOGIN_STATUS;
        $values['TWITTER_CLIENT_ID'] = $request->TWITTER_CLIENT_ID;
        $values['TWITTER_CLIENT_SECRET'] = $request->TWITTER_CLIENT_SECRET;
        $values['TWITTER_REDIRECT_URL'] = $request->TWITTER_REDIRECT_URL;


        $envFile = app()->environmentFilePath();
        $str = file_get_contents($envFile);
        if (count($values) > 0) {
            foreach ($values as $envKey => $envValue) {
                $str .= "\n";
                $keyPosition = strpos($str, "{$envKey}=");
                $endOfLinePosition = strpos($str, "\n", $keyPosition);
                $oldLine = substr($str, $keyPosition, $endOfLinePosition - $keyPosition);

                if (!$keyPosition || !$endOfLinePosition || !$oldLine) {
                    $str .= "{$envKey}=\"{$envValue}\"\n";
                } else {
                    $str = str_replace($oldLine, "{$envKey}=\"{$envValue}\"", $str);
                }
            }
        }
        $str = substr($str, 0, -1);
        if (!file_put_contents($envFile, $str))
            return false;

        Artisan::call('optimize:clear');

        return redirect()->back();
    }




    public function metaIndex()
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = __('Meta Management');
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavGlobalSettingsActiveClass'] = 'mm-active';
            $data['metaIndexActiveClass'] = 'active';

            $data['metas'] = $this->metaModel->getOrderById('DESC', 25);
            return view('admin.application_settings.meta_manage.index', $data);
        }
    }

    public function editMeta($uuid)
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = __('Edit Meta');
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavGlobalSettingsActiveClass'] = 'mm-active';
            $data['metaIndexActiveClass'] = 'active';
            $data['meta'] = $this->metaModel->getRecordByUuid($uuid);
            return view('admin.application_settings.meta_manage.edit', $data);
        }
    }

    public function updateMeta(Request $request, $uuid)
    {
        $data = [
            'meta_title' => $request->meta_title,
            'meta_description' => $request->meta_description,
            'meta_keyword' => $request->meta_keyword
        ];

        if ($request->hasFile('og_image')) {
            $data['og_image'] = $this->saveImage('meta', $request->og_image, null, null);
        }

        $this->metaModel->updateByUuid($data, $uuid);
        session()->flash('success', 'Updated Successful');

        return redirect()->route('settings.meta.index');
    }

    public function paymentMethod()
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = 'Payment Method Setting';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavPaymentOptionsSettingsActiveClass'] = 'mm-active';
            $data['paymentMethodSettingsActiveClass'] = 'active';
            $data['settings'] = Setting::pluck('option_value', 'option_key')->toArray();
            return view('admin.application_settings.payment-method', $data);
        }
    }

    public function mailConfiguration()
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = 'Mail Configuration';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavMailConfigSettingsActiveClass'] = 'mm-active';
            $data['mailConfigSettingsActiveClass'] = 'active';
            $data['settings'] = Setting::pluck('option_value', 'option_key')->toArray();
            return view('admin.application_settings.mail-configuration', $data);
        }
    }

  public function sendTestMail(Request $request)
{
    try {
        // Validate the request to ensure 'to' (recipient email) is provided.
        $request->validate([
            'to' => 'required|email',
        ]);

        // Fetch the mail template using alias.
        $template = MailTemplate::where('alias', 'employee_leave_notification')->first();

        if (!$template) {
            toastrMessage('error', 'Template not found.');
            return redirect()->back();
        }

        // Check if shortcodes is a string and decode it if necessary
        $shortcodes = $template->shortcodes;

        // Only decode if it's a JSON string, not already an array
        if (is_string($shortcodes)) {
            $shortcodes = json_decode($shortcodes, true);
        }

        // Replace shortcodes in the subject
        $subject = $template->subject;
        foreach ($shortcodes as $key => $value) {
            $subject = str_replace('{' . $key . '}', $value, $subject);
        }

        // Replace shortcodes in the body
        $body = $template->body;
        foreach ($shortcodes as $key => $value) {
            $body = str_replace('{' . $key . '}', $value, $body);
        }

        // Set email subject and view
        $view = 'emails.default'; // The markdown email view

        // Send the email with the dynamically replaced body and subject
        Mail::to($request->to)->send(new MarkdownMail($view, $subject, [
            'body' => $body,
            'short_codes' => $shortcodes,
        ]));


        return redirect()->back()->with('success', 'Mail successfully sent.');

    } catch (\Exception $exception) {
        if (env('APP_DEBUG')) {
            toastrMessage('error', $exception->getMessage());
        } else {
            toastrMessage('error', 'Something went wrong. Please check your email settings.');
        }
        return redirect()->back();
    }
}




    public function saveSetting(Request $request)
    {
        $this->updateSettings($request);

        return redirect()->back();
    }

    private function updateSettings($request)
    {
        $inputs = Arr::except($request->all(), ['_token']);
        $keys = [];

        foreach ($inputs as $k => $v) {
            $keys[$k] = $k;
        }
        foreach ($inputs as $key => $value) {

            $option = Setting::firstOrCreate(['option_key' => $key]);
            $option->option_value = $value;
            $option->save();
            setEnvironmentValue($key, $value);
        }

        Artisan::call('optimize:clear');
    }



    public function faqCMS()
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();

            $data['title'] = 'FAQ CMS';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavFAQSettingsActiveClass'] = 'mm-active';
            $data['faqCMSSettingsActiveClass'] = 'active';

            return view('admin.application_settings.faq.cms', $data);
        }
    }

    public function faqTab()
    {

        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = 'FAQ Tab';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavFAQSettingsActiveClass'] = 'mm-active';
            $data['faqCMSTabActiveClass'] = 'active';

            return view('admin.application_settings.faq.tab-service', $data);
        }
    }

    public function faqQuestion()
    {

        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = 'FAQ Question & Answer';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavFAQSettingsActiveClass'] = 'mm-active';
            $data['faqQuestionActiveClass'] = 'active';
            $data['faqQuestions'] = FaqQuestion::all();

            return view('admin.application_settings.faq.question', $data);
        }
    }

    public function faqQuestionUpdate(Request $request)
    {


        $now = now();
        if ($request['question_answers']) {
            if (count(@$request['question_answers']) > 0) {
                foreach ($request['question_answers'] as $question_answers) {
                    if (@$question_answers['question']) {
                        if (@$question_answers['id']) {
                            $question_answer = FaqQuestion::find($question_answers['id']);
                        } else {
                            $question_answer = new FaqQuestion();
                        }
                        $question_answer->question = @$question_answers['question'];
                        $question_answer->answer = @$question_answers['answer'];
                        $question_answer->updated_at = $now;
                        $question_answer->save();
                    }
                }
            }
        }

        FaqQuestion::where('updated_at', '!=', $now)->get()->map(function ($q) {
            $q->delete();
        });

        return redirect()->back();
    }

    public function supportTicketCMS()
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();

            $data['title'] = 'Support Ticket CMS';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavSupportSettingsActiveClass'] = 'mm-active';
            $data['supportCMSSettingsActiveClass'] = 'active';

            return view('admin.application_settings.support_ticket.cms', $data);
        }
    }

    public function supportTicketQuesAns()
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = 'Support Ticket Question & Answer';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavSupportSettingsActiveClass'] = 'mm-active';
            $data['supportQuestionActiveClass'] = 'active';
            $data['supportTickets'] = SupportTicketQuestion::all();

            return view('admin.application_settings.support_ticket.question', $data);
        }
    }

    public function supportTicketQuesAnsUpdate(Request $request)
    {
        $now = now();
        if ($request['question_answers']) {
            if (count(@$request['question_answers']) > 0) {
                foreach ($request['question_answers'] as $question_answers) {
                    if (@$question_answers['question']) {
                        if (@$question_answers['id']) {
                            $question_answer = SupportTicketQuestion::find($question_answers['id']);
                        } else {
                            $question_answer = new SupportTicketQuestion();
                        }
                        $question_answer->question = @$question_answers['question'];
                        $question_answer->answer = @$question_answers['answer'];
                        $question_answer->updated_at = $now;
                        $question_answer->save();
                    }
                }
            }
        }

        SupportTicketQuestion::where('updated_at', '!=', $now)->get()->map(function ($q) {
            $q->delete();
        });

        return redirect()->back();
    }



    public function deviceControl()
    {
        if (Session::has('LoggedIn')) {
            $data['user_session'] = User::where('id', Session::get('LoggedIn'))->first();
            $data['title'] = 'Device Control Settings';
            $data['navApplicationSettingParentActiveClass'] = 'mm-active';
            $data['subNavGlobalSettingsActiveClass'] = 'mm-active';
            $data['deviceControlActiveClass'] = 'active';

            return view('admin.application_settings.device_control', $data);
        }
    }





    public function deviceControlChange(Request $request)
    {
        $request->validate([
            'device_limit' => 'required|integer|min:1',
        ]);

        $option = Setting::firstOrCreate(['option_key' => 'device_limit']);
        $option->option_value = $request->device_limit;
        $option->save();

        $option = Setting::firstOrCreate(['option_key' => 'device_control']);
        $option->option_value = $request->device_control;
        $option->save();

        $this->showToastrMessage('success', 'Device Control has been changed');
        return redirect()->back();
    }

    public function questionAnsDelete(Request $request)
    {
        try {
            $question = SupportTicketQuestion::findOrFail($request->id);
            $question->delete();

            return response()->json(['success' => 'Support ticket question deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete support ticket question'], 500);
        }
    }
}
