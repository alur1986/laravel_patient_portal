<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/

use Illuminate\Support\Facades\Route;

Route::get('healthcheck', 'HealthCheck@index');

Route::post('/register/gclick', 'BookController@saveGClick');
Route::get('/book/meeting', 'BookController@getAWSMeeting');
Route::get('/book/appointment', 'HomeController@notFoundPage');
Route::get('/check/pdf', 'SubcriptionController@checkpdf');
Route::get('/book/appointments', 'BookController@appointments');
Route::get('/book/appointments/{step}', 'BookController@appointments');
Route::get('/book/appointments/get-timezone/{step}', 'BookController@getClinicTimeZone');
Route::get('/book/appointments/{clinicID}/{step}', 'BookController@appointments');
Route::get('booking/confirmation', 'BookController@showThankYou');
//Route::post('/book/appointments/bBQyDNe8pm7oPzox9jGXORxW6aK54J/{step}/{ajax}/{edit}', 'BookController@appointments');
Route::post('/book/appointments/{clinicID}/{step}/{ajax}/{edit}', 'BookController@appointments');
Route::post('/bookings/openPopup', 'BookController@openPopup');
Route::post('/bookings/getAvailablity', 'BookController@getAvailablity');
Route::post('/bookings/saveFormData', 'BookController@saveFormData');
Route::post('/bookings/getAvailableProvider', 'BookController@getAvailableProvider');
Route::post('/bookings/changeServiceType', 'BookController@changeServiceType');
Route::post('/bookings/saveHashedUser', 'BookController@saveHashedUser');
Route::post('/bookings/clearSessionData', 'BookController@clearSessionData');
Route::post('/bookings/checkIfCreditDetailsCanBeShown', 'BookController@checkIfCreditDetailsCanBeShown');
Route::post('/bookings/checkforposandfees', 'BookController@checkIfPOSAndFeesEnabled');
Route::post('/bookings/checkCancelationPolicyStatus', 'BookController@checkCancelationPolicyStatus');

Route::get('/survey/{key}', 'SurveyController@ShowSurvey');
Route::post('/save/survey', 'SurveyController@SaveSurvey');
Route::get('/thankyou/{key}', 'SurveyController@ShowThankyouPage');
Route::get('/google_oauth_event', 'BookController@googleAuthSyncEvent');
//~ Route::get('/sync_with_yahoo/{timeZoneFirstKey}/{timeZoneSecondKey}','BookController@yahooCalendarPatient');
//~ Route::get('/sync/icalender/{timeZoneFirstKey}/{timeZoneSecondKey}','BookController@syncIcanlendar');
Route::get('/sync_with_yahoo','BookController@yahooCalendarPatient');
Route::get('/sync/icalender','BookController@syncIcanlendar');
Route::get('/thankyou_sync','BookController@successSync');

Route::post('/bookings/verifyNumber', 'BookController@verifyNumber');
Route::post('/bookings/verifyOTP', 'BookController@verifyOTP');
Route::post('/bookings/resendOTP', 'BookController@resendOTP');

Route::post('/register/sendOTP', 'Controller@sendOTPOnEmail');
Route::get('/patient/register', 'PatientController@showRegistrationForm');
Route::post('/patient/register', 'PatientController@register');
Route::get('/activate/{key}', 'PatientController@activatePatient');
Route::get('/register/success', 'PatientController@registerSuccess');

Route::post('/register/verifyNumber', 'PatientController@verifyNumber');
Route::post('/register/verifyOTP', 'PatientController@verifyOTP');
Route::post('/register/resendOTP', 'PatientController@resendOTP');
Route::post('/check/email/on/new_signup', 'PatientController@checkEmailAtNewSignup');

Route::auth();

Route::group(['middleware' => ['web'], 'prefix'=> 'mobile'], function () {
    Route::group(['prefix'=> 'auth'], function () {
        Route::post('/mobile-auth', 'Auth\Mobile\MAuthController@loginByMobileAuthToken');
        Route::post('/login-OTP', "Auth\Mobile\MAuthController@loginOTP");
        Route::post('/login-email', "Auth\Mobile\MAuthController@loginWithEmail");
        Route::post('/send-OTP', "Auth\Mobile\MAuthController@sendOtp");
        Route::post('/verify-OTP', "Auth\Mobile\MAuthController@verifyOtp");
        Route::post('/register', "Auth\Mobile\MAuthController@register");
        Route::get('/check-number', "Auth\Mobile\MAuthController@checkIfNumberExists");
        Route::get('/activate/{key}', 'Mobile\MPatientUserController@activatePatient');
    });

    Route::group(['middleware' => ['jwt']], function () {
        #Mobile PatientUser module
        Route::get('/patient-user/get-user-clinics', 'Mobile\MPatientUserController@getUserClinics');
        Route::post('/patient-user/clinic', 'Mobile\MPatientUserController@addToClinic');
        Route::post('/patient-user/notification/{status}', 'Mobile\MPatientUserController@switchNotifications');
        Route::post('/save/profile-image', 'Mobile\MPatientUserController@editMobileProfileImage');
        Route::post('/save/profile', 'Mobile\MPatientUserController@editMobileProfile');

        #Mobile Appointment module
        Route::get('/appointments/{period}', 'Mobile\MAppointmentController@getAppointments');
        Route::get('/appointment/detail', 'Mobile\MAppointmentController@getAppointmentDetails');
        Route::get('/appointment/invoices', 'Mobile\MAppointmentController@getAppointmentInvoices');
        Route::get('/appointment/receipt-PDF', 'Mobile\MAppointmentController@getReceiptPDF');
        Route::get('/appointment/invoice-PDF', 'Mobile\MAppointmentController@getAppointmentInvoicePDF');
        Route::get('/appointment/prescriptions', 'Mobile\MAppointmentController@getAppointmentPrescriptions');
        Route::get('/appointment/prescription-PDF', 'Mobile\MAppointmentController@getAppointmentPrescriptionPDF');
        Route::get('/appointment/provider-schedule/{id}', 'Mobile\MAppointmentController@getAppointmentProviderSchedule');
        Route::post('/appointment/cancel/{id}', 'Mobile\MAppointmentController@cancelAppointment');
        Route::post('/appointment/reschedule/{id}', 'Mobile\MAppointmentController@rescheduleAppointment');

        #Mobile Wallet module
        Route::get('/wallet', 'Mobile\MWalletController@clientWallet');

        #Mobile Services module
        Route::post('/get_services', 'Mobile\MServiceController@getServicesList');
        Route::post('/get_service_providers', 'Mobile\MServiceController@getServiceProviders');

        #Mobile Booking module
        Route::get('/booking/clinics', 'Mobile\MBookController@getClinics');
        Route::get('/booking/provider-schedule', 'Mobile\MBookController@getProviderSchedule');
        Route::post('/booking/book-first-available', 'Mobile\MBookController@bookFirstAvailable');
        Route::post('/booking/book-appointment', 'Mobile\MBookController@bookAppointment');

        #Mobile TreatmentInstruction module
        Route::get('/treatment-instructions', 'Mobile\MTreatmentInstructionController@getTreatmentInstructions');

        #Mobile QuestionnaireConsent module
        Route::get('/get-tasks-to-complete', 'Mobile\MQuestionnaireConsentController@getTasksToComplete');
        Route::post('/tasks-to-complete/consent/{id}', 'Mobile\MQuestionnaireConsentController@saveConsent');
        Route::post('/tasks-to-complete/questionnaire/{id}', 'Mobile\MQuestionnaireConsentController@saveQuestionnaire');

        #Mobile Membership module
        Route::get('/memberships/active', 'Mobile\MMembershipController@getActiveMemberships');
        Route::get('/memberships/membership-contract/{id}', 'Mobile\MMembershipController@getMembershipContract');
        Route::get('/memberships/get-membership-types', 'Mobile\MMembershipController@getMembershipAvailableTypes');
        Route::post('/memberships/create-membership', 'Mobile\MMembershipController@createMembership');
        Route::get('/memberships/create-membership', 'Mobile\MMembershipController@createMembership');

        #Mobile PatientInsurance module
        Route::get('/get-insurance', 'Mobile\MPatientInsuranceController@getInsurance');
        Route::post('/update-insurance', 'Mobile\MPatientInsuranceController@updateInsurance');

        #Mobile MedicalHistory module
        Route::get('/medical-history', 'MedicalHistoryController@index');
        Route::post('/save/medical-history', 'MedicalHistoryController@store');

        Route::get('/chat/get-client', 'Mobile\MChatController@getClient');
    });
});

Route::group(['middleware' => ['web']], function () {
	Route::post('/check/email/exist', 'Controller@checkEmailExist');
    Route::post('/check/email/on/signup', 'Controller@checkEmailAtSignup');
    Route::post('/check/email/on/reset/password', 'Controller@checkEmailAtResetPassword');
    Route::post('/match/password', 'Controller@matchPassword');
    Route::get('/home', 'HomeController@dashboard');
    Route::post('/check/email/on/new_signup', 'PatientController@checkEmailAtNewSignup');

	Route::get('/', 'HomeController@index');
	Route::get('/dashboard', 'HomeController@dashboard');
	Route::post('/dashboard', 'HomeController@dashboard');
	Route::get('/edit/profile', 'HomeController@editProfile');
	Route::get('/change-password', 'HomeController@changePassword');
//	Route::post('/save/password', 'HomeController@changePassword');
    Route::post('/save/password', 'HomeController@editProfile');
    Route::get('/medical-history-old', 'HomeController@getMedicalHistory');
    Route::get('/medical-history', 'MedicalHistoryController@index');
    Route::post('/save/medical-history', 'MedicalHistoryController@store');
    Route::post('/save/profile', 'HomeController@editProfile');
    Route::post('/image-upload', 'HomeController@imageUpload');
    Route::post('/uploads/file/questionnaire', 'HomeController@uploadQuestionnarieFile');
    Route::post('/save/health/questionnaire/{id}/{appointment_id}/{filledQuestionnaireId}', 'HomeController@saveHealthQuestionnarie');

	Route::get('/membership-details', 'HomeController@showMembershipDetails');
	Route::get('/patient_wallet', 'WalletController@clientWallet');
	Route::get('/download_agreement/{id}', 'HomeController@downloadAgreement');

	Route::group([ 'prefix' => 'appointments' ], function () {
		Route::get('/appointment-detail/{id}', 'AppointmentController@appointmentDetail');
		Route::get('/get/provider/availability/{provider_id}/{appointment_id}', 'AppointmentController@getProviderAvailabilityDays');
		Route::get('/questionnair/{id}/{appointment_id}/{service_id}', 'AppointmentController@getQuestinnair');
		Route::get('/treatment-instructions/{id}/{appointment_id}/{service_id}', 'AppointmentController@getTreatmentInstruction');
		Route::get('/post-treatment-instructions/{id}/{appointment_id}/{service_id}', 'AppointmentController@getPostTreatmentInstruction');
		Route::get('/cancel/{id}', 'AppointmentController@cancelAppointment');
		Route::post('/re-schedule/{id}', 'AppointmentController@reScheduleAppointment');
		Route::post('/save/questionnair', 'AppointmentController@saveAppointmentQuestionnair');
		Route::post('/save/treatment_instruction', 'AppointmentController@saveTreatmentInstruction');
		Route::post('/get/provider/time', 'AppointmentController@getProviderAvailabilityTime');
		Route::post('/get/endTime', 'AppointmentController@getAppointmentEndTime');
		Route::get('/health_questionnair/{id}/{appointment_id}/{service_id}', 'HomeController@getHealthQuestinnair');
		Route::get('/consent/{id}/{appointment_id}/{service_id}', 'HomeController@getHealthConsent');
		Route::post('/save/consent', 'HomeController@saveConsent');
	});

	Route::get('/become-a-member', 'SubcriptionController@becomeMember');
	Route::get('/become-a-member/{status}', 'SubcriptionController@becomeMember');
	Route::post('/become-a-member', 'SubcriptionController@becomeMember');
	Route::get('/become-a-member/success','SubcriptionController@thankyou');
	Route::get('/pick_timezone', 'BookController@pickTimeZone');
	Route::get('/validate_coupon_code','SubcriptionController@validateCouponCode');
	Route::get('/become-a-member-yearly', 'SubcriptionController@becomeMember');
	Route::post('/become-a-member-yearly', 'SubcriptionController@becomeMember');
	Route::get('/get-multi-tier-data/{id}','SubcriptionController@getMultierData');

    Route::group(['prefix' => 'chat'], function() {
        Route::get('', 'ChatController@index');
    });
});
