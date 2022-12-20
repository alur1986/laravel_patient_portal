<?php

namespace App\Http\Controllers\Mobile;

use App\Account;
use App\PatientAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Session;
use App\Patient;
use App\PosInvoiceItem;
use App\Services\PatientService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class MWalletController extends Controller
{
    /**
     * @SWG\Get(
     *      path="/mobile/wallet",
     *      operationId="clientWallet",
     *      summary="Get client wallet info",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Your patient not exists in this clinic",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | unexisting_patient"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Wallet info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully retrieved"),
     *              @SWG\Property(
     *                  property="date",
     *                  type="array",
     *                  @SWG\Items(
     *                      type="object",
     *                      @SWG\Property(property="client_wallet", type="object", example="{}"),
     *                      @SWG\Property(property="currency_symbol", type="string", example="$"),
     *                      @SWG\Property(
     *                          property="credits",
     *                          type="array",
     *                          @SWG\Items(
     *                              type="object",
     *                          ),
     *                      ),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function clientWallet(Request $request): JsonResponse
    {
        $input = $request->all();

        if (isset($input['account_id'])) {
            $account_id = $input['account_id'];
        } else {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'missed_required_fields');
        }

        $patientUserId = Auth::user()->id;

        $patient = PatientAccount::where('patient_user_id', $patientUserId)->where('account_id', $account_id)->first();

        if (!$patient) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'unexisting_patient');
        }

        $patient_id = $patient['patient_id'];

        $account_data = Account::where('id', $account_id)->select('database_name')->get()->toArray();
        $database_name = $account_data[0]['database_name'];

        $account_data = Account::where('id', $account_id)->select('stripe_currency')->get()->toArray();
        $currency_code = $account_data[0]['stripe_currency'];

        $currency_symbol = $this->getCurrencySymbol($currency_code);

        config(['database.connections.juvly_practice.database' => $database_name]);

        $cardVouchers = PatientService::getCardVouchers($patient_id);

        $egift_card_voucher_ids = [];
        foreach ($cardVouchers as $key => $redemptions) {
            $egift_card_voucher_ids[] = $redemptions['egift_card_voucher_id'];
        }

        $patientData = Patient::with(['patientMembershipSubscription', 'patientsCardsOnFile', 'clientWallet.patientWalletCredit', 'monthlyMembershipInvoice' => function ($monthly_member_invoice) {
            $monthly_member_invoice->where('payment_status', '<>', 'pending')->orderBy('id', 'DESC');
        }])->find($patient_id);

        $data = array();

        $egiftcard_credit = PosInvoiceItem::where('product_type', 'egiftcard')
            ->whereHas('posInvoice', function ($pos_invoice_where) use ($patient_id) {
                $pos_invoice_where->where('patient_id', $patient_id);
            })
            ->with(['posInvoice' => function ($pos_invoice) use ($patient_id) {
                $pos_invoice->where('patient_id', $patient_id)
                    ->with(['egiftCardsPurchase' => function ($eGiftCardPurchase) {
                        $eGiftCardPurchase->with(['egiftCardVoucher' => function ($e_gift_voucher) {
                            $e_gift_voucher->where('is_expired', '<>', 1)->where('balance', '<>', 0);
                        }]);
                    }]);
            }])
            ->get();

        $egiftcard_details = array();
        foreach ($egiftcard_credit as $key => $posInvoice) {
            foreach ($posInvoice['posInvoice']['egiftCardsPurchase'] as $key2 => $egiftCardVoucher) {
                if (!in_array($egiftCardVoucher['egift_card_voucher_id'], $egift_card_voucher_ids) && $egiftCardVoucher['egiftCardVoucher']['balance'] > 0) {
                    $egiftcard_details[$egiftCardVoucher['egift_card_voucher_id']]['amount'] = $egiftCardVoucher['egiftCardVoucher']['amount'];
                    $egiftcard_details[$egiftCardVoucher['egift_card_voucher_id']]['balance'] = $egiftCardVoucher['egiftCardVoucher']['balance'];

                    $egiftcard_details[$egiftCardVoucher['egift_card_voucher_id']]['redemption_code'] = $egiftCardVoucher['egiftCardVoucher']['redemption_code'];
                }
            }
        }

        if ($patientData) {
            if (isset($patientData['clientWallet'])) {
                $creditbalance = $patientData['clientWallet']['dollar_credit'];
                $bdbalance = $patientData['clientWallet']['bd_credit'];
                $aspirebalance = $patientData['clientWallet']['aspire_credit'];
                $creditdate = date('Y-m-d H:i:s', strtotime("+9 days"));
                $bdcreditdate = date('Y-m-d H:i:s', strtotime("+7 days"));
                $aspirecreditdate = date('Y-m-d H:i:s', strtotime("+5 days"));
                $egiftcreditdate = date('Y-m-d H:i:s', strtotime("+3 days"));

                $tempArray = array('row_type' => 'credit', 'product_name' => 'Dollar Credit', 'total_units' => '', 'balance_units' => '', 'discount_package_name' => '', 'discount_package_type' => '', 'balance' => $creditbalance, 'date' => $creditdate, 'credit_type' => 'dollar');
                $data['credit'] = $tempArray;

                $tempArray = array('row_type' => 'credit', 'product_name' => 'BD Credit', 'total_units' => '', 'balance_units' => '', 'discount_package_name' => '', 'discount_package_type' => '', 'balance' => $bdbalance, 'date' => $bdcreditdate, 'credit_type' => 'bd');
                $data['bd_credit'] = $tempArray;

                $tempArray = array('row_type' => 'credit', 'product_name' => 'Aspire Credit', 'total_units' => '', 'balance_units' => '', 'discount_package_name' => '', 'discount_package_type' => '', 'balance' => $aspirebalance, 'date' => $aspirecreditdate, 'credit_type' => 'aspire');
                $data['aspire_credit'] = $tempArray;
                if (!empty($egiftcard_details)) {
                    foreach ($egiftcard_details as $key => $vouchers) {

                        $code = (string)$vouchers['redemption_code'];
                        $redemption_code = implode("-", str_split($code, "4"));
                        $tempArray = array('row_type' => 'credit', 'product_name' => 'eGiftcard Credit worth ' . getFormattedCurrency($vouchers['amount']) . ' (' . $redemption_code . ')', 'total_units' => '', 'balance_units' => '', 'egiftcard_name' => '', 'egiftcard_name_type' => '', 'balance' => $vouchers['balance'], 'date' => $egiftcreditdate, 'credit_type' => 'egiftcard');
                        $data['egiftcard_credit' . $key] = $tempArray;
                    }
                }

            }
        }

        if (!empty($cardVouchers)) {
            foreach ($cardVouchers as $voucher) {
                $voucherDate = date('Y-m-d H:i:s', strtotime("+4 days"));
                $code = chunk_split($voucher['eGiftCardVoucher']['redemption_code'], 4, ' ');
                $tempArray = array('row_type' => 'voucher', 'product_name' => 'eGiftcard with redemption code  ' . $code, 'total_units' => '', 'balance_units' => '', 'discount_package_name' => '', 'discount_package_type' => '', 'balance' => $voucher['eGiftCardVoucher']['balance'], 'date' => $voucherDate, 'credit_type' => '');
                $data[] = $tempArray;
            }
        }

        if ( !empty($data) ) {
            usort($data, array($this, "date_compare"));
        }

        $final_data = [];
        $final_data['patient_wallet'] = $patientData['clientWallet'];
        $final_data['data'] = $data;

        $resData = [
            'client_wallet' => $final_data['patient_wallet'],
            'currency_symbol' => $currency_symbol,
            'credits' => $final_data['data']
        ];

        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $resData);
    }

    public function date_compare($a, $b){
        $date_format = 'd/m/Y';
        $a = $this->refineDMYDateFormat($date_format, $a);
        $b = $this->refineDMYDateFormat($date_format, $b);

        $t1 = strtotime($a['date']);
        $t2 = strtotime($b['date']);

        return $t2 - $t1; // For descending (reverse for ascending)
    }
}
