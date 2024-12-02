<?php

namespace App\Http\Controllers\Api\Storefront;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use App\Utils\BusinessUtil;
use App\Utils\CashRegisterUtil;
use App\Utils\ContactUtil;
use App\Utils\NotificationUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use App\Variation;
use Modules\Connector\Transformers\SellTransactionResource;
use App\BusinessLocation;
use App\Product;
use App\TaxRate;
use App\Unit;
use App\Contact;
use App\Business;
use App\Transaction;
use App\TransactionSellLine;
use App\TransactionPayment;
use DB;
use App\Transformers\SellResource;

/**
 * @group Sales management
 * @authenticated
 *
 * APIs for managing sales
 */
class SellController extends ApiController
{
    /**
     * All Utils instance.
     *
     */
    protected $contactUtil;
    protected $productUtil;
    protected $businessUtil;
    protected $transactionUtil;
    protected $cashRegisterUtil;
    protected $moduleUtil;
    protected $notificationUtil;

    /**
     * Constructor
     *
     * @param ProductUtils $product
     * @return void
     */
    public function __construct(
        ContactUtil $contactUtil,
        ProductUtil $productUtil,
        BusinessUtil $businessUtil,
        TransactionUtil $transactionUtil,
        CashRegisterUtil $cashRegisterUtil,
        NotificationUtil $notificationUtil
    ) {
        $this->contactUtil = $contactUtil;
        $this->productUtil = $productUtil;
        $this->businessUtil = $businessUtil;
        $this->transactionUtil = $transactionUtil;
        $this->cashRegisterUtil = $cashRegisterUtil;
        $this->notificationUtil = $notificationUtil;

        $this->dummyPaymentLine = ['method' => 'cash', 'amount' => 0, 'note' => '', 'card_transaction_number' => '', 'card_number' => '', 'card_type' => '', 'card_holder_name' => '', 'card_month' => '', 'card_year' => '', 'card_security' => '', 'cheque_number' => '', 'bank_account_number' => '',
        'is_return' => 0, 'transaction_no' => ''];
        parent::__construct();
    }

    public function index()
    {
        $business_id = $this->business_id;

        $sells = Transaction::where('business_id', $business_id)
            ->where('contact_id',$this->getContact())
            ->with(['contact','billingAddressWithZoneAndCity','shippingAddressWithZoneAndCity', 'pathaoOrder', 'sell_lines' => function ($q) {
                $q->whereNull('parent_sell_line_id');
            },'sell_lines.product', 'sell_lines.product.unit', 'sell_lines.variations', 'sell_lines.variations.product_variation', 'payment_lines', 'sell_lines.modifiers', 'sell_lines.lot_details', 'tax', 'sell_lines.sub_unit', 'table', 'service_staff', 'sell_lines.service_staff', 'types_of_service', 'sell_lines.warranties', 'media'])
            ->get();
        
        foreach($sells as $index => $sell){
            foreach ($sells[$index]->sell_lines as $key => $value) {
                if (!empty($value->sub_unit_id)) {
                    $formated_sell_line = $this->transactionUtil->recalculateSellLineTotals($business_id, $value);
                    $sells[$index]->sell_lines[$key] = $formated_sell_line;
                }
            }

            $total_paid = 0;
            foreach($sells[$index]->payment_lines as $payment_line){
                $total_paid += $payment_line->amount;
            }
            $sells[$index]->total_paid = $total_paid;

            $sells[$index]->transaction_date = date("jS M, Y",strtotime($sells[$index]->transaction_date));

            $sells[$index]->invoice_url = $this->transactionUtil->getInvoiceUrl($sells[$index]->id, $business_id);
        }

        return SellResource::collection($sells);

    }

    public function show($invoice)
    {
        $business_id = $this->business_id;

        $query = Transaction::where('business_id', $business_id)
            ->where('invoice_no', $invoice)
            ->with(['contact','billingAddressWithZoneAndCity','shippingAddressWithZoneAndCity', 'pathaoOrder', 'sell_lines' => function ($q) {
                $q->whereNull('parent_sell_line_id');
            },'sell_lines.product', 'sell_lines.product.unit', 'sell_lines.variations', 'sell_lines.variations.product_variation', 'payment_lines', 'sell_lines.modifiers', 'sell_lines.lot_details', 'tax', 'sell_lines.sub_unit', 'table', 'service_staff', 'sell_lines.service_staff', 'types_of_service', 'sell_lines.warranties', 'media']);
        
        
        $query->where('contact_id',$this->getContact());

        $sell = $query->first();

        if(!$sell){
            return response()->json([
                'error' => [
                    'message' => 'Unauthorized'
                ]
            ], 401);
        }
        
        foreach ($sell->sell_lines as $key => $value) {
            if (!empty($value->sub_unit_id)) {
                $formated_sell_line = $this->transactionUtil->recalculateSellLineTotals($business_id, $value);
                $sell->sell_lines[$key] = $formated_sell_line;
            }
        }

        $total_paid = 0;
        foreach($sell->payment_lines as $payment_line){
            $total_paid += $payment_line->amount;
        }
        $sell->total_paid = $total_paid;

        $sell->transaction_date = date("jS M, Y",strtotime($sell->transaction_date));

        $sell->invoice_url = $this->transactionUtil->getInvoiceUrl($sell->id, $business_id);

        // return SellResource::collection($sells);
        return $sell;
    }

    public function store(Request $request)
    {
        $is_direct_sale = true;

        try {
            $input = $request->except('_token');
            
            if (!empty($input['products'])) {
                $business = Business::find($this->business_id);
                $business_id = $this->business_id;
                $input['location_id'] = $business->storefront ? $business->storefront->location_id : $business->locations()->first()->id;
                $input['status'] = 'final';
                $input['is_created_from_api'] = 1;
                $input['shipping_status'] = 'ordered';
                $input['tax_rate_id'] = null;
                $input['additional_notes'] = $input['note'];
                
                $invoice_total = $this->productUtil->calculateInvoiceTotal($input['products'], $input['tax_rate_id']);
                
                $input['final_total'] = $invoice_total['final_total'] + $input['shipping_charges'];
                DB::beginTransaction();
                $input['discount_amount'] = 0;
                $input['commission_agent'] = !empty($request->input('commission_agent')) ? $request->input('commission_agent') : null;

                $input['is_direct_sale'] = 1;

                $input['transaction_date'] =  \Carbon::now();

                if (empty($request->input('preferable_delivery_date'))) {
                    $input['preferable_delivery_date'] =  \Carbon::tomorrow();
                } else {
                    $mysql_format = 'Y-m-d';
                    $date_format = $business->date_format;
                    if ($business->time_format == 12) {
                        $date_format = $date_format . ' h:i A';
                    } else {
                        $date_format = $date_format . ' H:i';
                    }
                    $mysql_format = 'Y-m-d H:i:s';
                    $input['preferable_delivery_date'] = \Carbon::createFromFormat($date_format, $request->input('preferable_delivery_date'))->format($mysql_format);
                }

                if(request()->header('Authorization')){
                    $input['contact_id'] = $this->getContact();
                }else{
                    $contactExist = DB::table('contacts')->where('mobile',$input['phone'])->first();
                    if($contactExist){
                        $input['contact_id'] = $contactExist->id;
                    }else{
                        $contactInput['type'] = 'customer';
                        $contactInput['contact_id'] = null;
                        $contactInput['first_name'] = $input['firstName'];
                        $contactInput['last_name'] = $input['lastName'];
                        $contactInput['mobile'] = $input['phone'];
                        $contactInput['email'] = $input['email'];
                        $contactInput['name'] = implode(' ', [$contactInput['first_name'], $contactInput['last_name']]);
                        $contactInput['business_id'] = $business_id;
                        $contactInput['created_by'] = $business->owner_id;

                        $contact = $this->contactUtil->createNewContact($contactInput);
                        $input['contact_id'] = $contact['data']->id;
                    }
                }

                $input['name'] = implode(' ', [$input['firstName'], $input['lastName']]);

                if(!array_key_exists('shipping_address_id',$input)){
                    $hasShippingAddress = DB::table('addresses')
                        ->where('type','shipping')
                        ->where('zone_id',$input['zone'])
                        ->where('address',$input['address'])
                        ->where('name',$input['name'])
                        ->where('number',$input['phone'])
                        ->where('contact_id',$input['contact_id'])
                        ->first();
                    if($hasShippingAddress){
                        $input['shipping_address_id'] = $hasShippingAddress->id;
                    }else{
                        $input['shipping_address_id'] = DB::table('addresses')->insertGetId([
                            'type' => 'shipping',
                            'zone_id' => $input['zone'],
                            'address' => $input['address'],
                            'name' => $input['name'],
                            'number' => $input['phone'],
                            'contact_id' => $input['contact_id'],
                            'created_at' => now(),
                        ]);
                    }
                }

                if(!array_key_exists('billing_address_id',$input)){
                    $hasBillingAddress = DB::table('addresses')
                        ->where('type','billing')
                        ->where('zone_id',$input['zone'])
                        ->where('address',$input['address'])
                        ->where('name',$input['name'])
                        ->where('number',$input['phone'])
                        ->where('contact_id',$input['contact_id'])
                        ->first();
                    if($hasBillingAddress){
                        $input['billing_address_id'] = $hasBillingAddress->id;
                    }else{
                        $input['billing_address_id'] = DB::table('addresses')->insertGetId([
                            'type' => 'billing',
                            'zone_id' => $input['zone'],
                            'address' => $input['address'],
                            'name' => $input['name'],
                            'number' => $input['phone'],
                            'contact_id' => $input['contact_id'],
                            'created_at' => now(),
                        ]);
                    }
                }

                //Customer group details
                $contact_id = $request->get('contact_id', null);
                $cg = $this->contactUtil->getCustomerGroup($business_id, $contact_id);
                $input['customer_group_id'] = (empty($cg) || empty($cg->id)) ? null : $cg->id;

                //set selling price group id
                $price_group_id = $request->has('price_group') ? $request->input('price_group') : null;

                //If default price group for the location exists
                $price_group_id = $price_group_id == 0 && $request->has('default_price_group') ? $request->input('default_price_group') : $price_group_id;

                $input['sale_note'] = !empty($input['additional_notes']) ? $input['additional_notes'] : null;

                $invoice_scheme = DB::table('invoice_schemes')->where('business_id',$business_id)->first();
                $input['invoice_scheme_id'] = $invoice_scheme->id;

                $input['selling_price_group_id'] = $price_group_id;

                $user_id = $business->owner_id;

                $transaction = $this->transactionUtil->createSellTransaction($business_id, $input, $invoice_total, $user_id);

                foreach ($input['products'] as $key => $product) {
                    $productData = Product::find($product['product_id']);
                    $input['products'][$key]['product_unit_id'] = $productData->unit_id;
                    $input['products'][$key]['sub_unit_id'] = $productData->unit_id;
                }

                $this->transactionUtil->createOrUpdateSellLines($transaction, $input['products'], $input['location_id']);

                $is_credit_sale = isset($input['is_credit_sale']) && $input['is_credit_sale'] == 1 ? true : false;

                if (!$transaction->is_suspend && !empty($input['payment']) && !$is_credit_sale) {
                    $this->transactionUtil->createOrUpdatePaymentLines($transaction, $input['payment']);
                }

                //Check for final and do some processing.
                if ($input['status'] == 'final') {
                    //update product stock
                    foreach ($input['products'] as $product) {
                        $decrease_qty = $this->productUtil
                                    ->num_uf($product['quantity']);
                        if (!empty($product['base_unit_multiplier'])) {
                            $decrease_qty = $decrease_qty * $product['base_unit_multiplier'];
                        }

                        if ($product['enable_stock']) {
                            $this->productUtil->decreaseProductQuantity(
                                $product['product_id'],
                                $product['variation_id'],
                                $input['location_id'],
                                $decrease_qty
                            );
                        }

                        if ($product['product_type'] == 'combo') {
                            //Decrease quantity of combo as well.
                            $this->productUtil
                                ->decreaseProductQuantityCombo(
                                    $product['combo'],
                                    $input['location_id']
                                );
                        }
                    }

                    //Add payments to Cash Register
                    if (!$is_direct_sale && !$transaction->is_suspend && !empty($input['payment']) && !$is_credit_sale) {
                        $this->cashRegisterUtil->addSellPayments($transaction, $input['payment']);
                    }

                    //Update payment status
                    $payment_status = $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

                    $transaction->payment_status = $payment_status;

                    //Allocate the quantity from purchase and add mapping of
                    //purchase & sell lines in
                    //transaction_sell_lines_purchase_lines table
                    $business_details = $this->businessUtil->getDetails($business_id);
                    $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

                    $business = ['id' => $business_id,
                                    'accounting_method' => $business_details->accounting_method,
                                    'location_id' => $input['location_id'],
                                    'pos_settings' => $pos_settings
                                ];
                    $this->transactionUtil->mapPurchaseSell($business, $transaction->sell_lines, 'purchase');
                }

                if (!empty($transaction->sales_order_ids)) {
                    $this->transactionUtil->updateSalesOrderStatus($transaction->sales_order_ids);
                }

                $this->transactionUtil->activityLog($transaction, 'added');

                DB::commit();

                $msg = trans("sale.pos_sale_added");
                $receipt = '';
                $invoice_layout_id = 1;
                $print_invoice = false;
                if (!$is_direct_sale) {
                    if ($input['status'] == 'draft') {
                        $msg = trans("sale.draft_added");

                        if ($input['is_quotation'] == 1) {
                            $msg = trans("lang_v1.quotation_added");
                            $print_invoice = true;
                        }
                    } elseif ($input['status'] == 'final') {
                        $print_invoice = true;
                    }
                }

                if ($transaction->is_suspend == 1 && empty($pos_settings['print_on_suspend'])) {
                    $print_invoice = false;
                }
                
                if ($print_invoice) {
                    $receipt = $this->receiptContent($business_id, $input['location_id'], $transaction->id, null, false, true, $invoice_layout_id);
                }

                // From Show
                $sell = Transaction::where('business_id', $business_id)
                    ->where('invoice_no', $transaction->invoice_no)
                    ->with(['contact','billingAddressWithZoneAndCity','shippingAddressWithZoneAndCity', 'pathaoOrder', 'sell_lines' => function ($q) {
                        $q->whereNull('parent_sell_line_id');
                    },'sell_lines.product', 'sell_lines.product.unit', 'sell_lines.variations', 'sell_lines.variations.product_variation', 'payment_lines', 'sell_lines.modifiers', 'sell_lines.lot_details', 'tax', 'sell_lines.sub_unit', 'table', 'service_staff', 'sell_lines.service_staff', 'types_of_service', 'sell_lines.warranties', 'media'])->first();
                
                foreach ($sell->sell_lines as $key => $value) {
                    if (!empty($value->sub_unit_id)) {
                        $formated_sell_line = $this->transactionUtil->recalculateSellLineTotals($business_id, $value);
                        $sell->sell_lines[$key] = $formated_sell_line;
                    }
                }

                $total_paid = 0;
                foreach($sell->payment_lines as $payment_line){
                    $total_paid += $payment_line->amount;
                }
                $sell->total_paid = $total_paid;

                $sell->transaction_date = date("jS M, Y",strtotime($sell->transaction_date));

                $sell->invoice_url = $this->transactionUtil->getInvoiceUrl($sell->id, $business_id);
                // From Show

                $output = ['success' => 1, 'transaction' => $sell->toArray()];
            } else {
                $output = ['success' => 0, 'msg' => 'No products'];
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            $msg = trans("messages.something_went_wrong");
                
            if (get_class($e) == \App\Exceptions\PurchaseSellMismatch::class) {
                $msg = $e->getMessage();
            }
            if (get_class($e) == \App\Exceptions\AdvanceBalanceNotAvailable::class) {
                $msg = $e->getMessage();
            }

            $output = ['success' => 0, 'msg' => $msg];
        }

        return $output;
    }

    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user(); 

            $business_id = $user->business_id;
            $business = Business::find($business_id);

            $sell_data = $request->input();
            $sell_data['business_id'] = $user->business_id;

            $transaction_before = Transaction::where('business_id', $user->business_id)->with(['payment_lines'])
                                    ->findOrFail($id);

            //Check if location allowed
            if (!$user->can_access_this_location($transaction_before->location_id)) {
                throw new \Exception("User not allowed to access location with id " . $input['location_id']);
            }

            $status_before =  $transaction_before->status;
            $rp_earned_before = $transaction_before->rp_earned;
            $rp_redeemed_before = $transaction_before->rp_redeemed;

            $sell_data['location_id'] = $transaction_before->location_id;
            $input = $this->__formatSellData($sell_data, $transaction_before);
            $discount = ['discount_type' => $input['discount_type'],
                                'discount_amount' => $input['discount_amount']
                            ];
            $invoice_total = $this->productUtil->calculateInvoiceTotal($input['products'], $input['tax_rate_id'], $discount);

            //Begin transaction
            DB::beginTransaction();

            $transaction = $this->transactionUtil->updateSellTransaction($transaction_before, $business_id, $input, $invoice_total, $user->id, false);

            //Update Sell lines
            $deleted_lines = $this->transactionUtil->createOrUpdateSellLines($transaction, $input['products'], $input['location_id'], true, $status_before, [], false);
            if (!empty($input['payment']) && $transaction->is_suspend == 0) {

                $change_return = $this->dummyPaymentLine;
                $change_return['amount'] = $input['change_return'];
                $change_return['is_return'] = 1;
                if (!empty($input['change_return_id'])) {
                    $change_return['id'] = $input['change_return_id'];
                }
                $input['payment'][] = $change_return;

               $this->transactionUtil->createOrUpdatePaymentLines($transaction, $input['payment'], $business_id, $user->id, false);
            }
            
            if ($business->enable_rp == 1) {
                $this->transactionUtil->updateCustomerRewardPoints($transaction->contact_id, $transaction->rp_earned, $rp_earned_before, $transaction->rp_redeemed, $rp_redeemed_before);
            }

            //Update payment status
            $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

            //Update product stock
            $this->productUtil->adjustProductStockForInvoice($status_before, $transaction, $input, false);

            //Allocate the quantity from purchase and add mapping of
            //purchase & sell lines in
            //transaction_sell_lines_purchase_lines table
            $business_details = $this->businessUtil->getDetails($business_id);
            $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

            $business = ['id' => $business_id,
                            'accounting_method' => $business->accounting_method,
                            'location_id' => $input['location_id'],
                            'pos_settings' => $pos_settings
                        ];
            $this->transactionUtil->adjustMappingPurchaseSell($status_before, $transaction, $business, $deleted_lines);

            $updated_transaction = Transaction::where('business_id', $user->business_id)->with(['payment_lines'])
                                    ->findOrFail($id);
            $output = $updated_transaction;

            $client = $this->getClient();

            $this->transactionUtil->activityLog($updated_transaction, 'edited', $transaction_before, ['from_api' => $client->name]);
            DB::commit();
        } catch(ModelNotFoundException $e){
            DB::rollback();
            $output = $this->modelNotFoundExceptionResult($e);
        }
        catch (\Exception $e) {
            DB::rollback();
            $output = $this->otherExceptions($e);
        }

        return $output;
    }

    private function __getValue($key, $data, $obj, $default = null, $db_key = null)
    {
        $value = $default;

        if (isset($data[$key])) {
            $value = $data[$key];
        } else if (!empty($obj)) {
            $key = !empty($db_key) ? $db_key : $key;
            $value = $obj->$key;
        }

        return $value;
    }

    /**
     * Formats input form data to sell data
     * @param  array $data
     * @return array
     */
    private function __formatSellData($data, $transaction = null)
    {

        $business_id = $data['business_id'];
        $location = BusinessLocation::where('business_id', $business_id)
                                    ->findOrFail($data['location_id']);

        $customer_id = $this->__getValue('contact_id', $data, $transaction, null);
        $contact = Contact::where('business_id', $data['business_id'])
                            ->whereIn('type', ['customer', 'both'])
                            ->findOrFail($customer_id);

        $cg = $this->contactUtil->getCustomerGroup($business_id, $contact->id);
        $customer_group_id = (empty($cg) || empty($cg->id)) ? null : $cg->id;
        $formated_data = [
            'business_id' => $business_id,
            'location_id' => $location->id,
            'contact_id' => $contact->id,
            'customer_group_id' => $customer_group_id,
            'transaction_date' => $this->__getValue('transaction_date', $data, 
                                $transaction,  \Carbon::now()->toDateTimeString()),
            'invoice_no' => $this->__getValue('invoice_no', $data, $transaction, null, 'invoice_no'),
            'status' => $this->__getValue('status', $data, $transaction, 'final'),
            'sub_status' => $this->__getValue('sub_status', $data, $transaction, null),
            'sale_note' => $this->__getValue('sale_note', $data, $transaction),
            'staff_note' => $this->__getValue('staff_note', $data, $transaction),
            'commission_agent' => $this->__getValue('commission_agent', 
                                    $data, $transaction),
            'shipping_details' => $this->__getValue('shipping_details', 
                                    $data, $transaction),
            'shipping_address' => $this->__getValue('shipping_address', 
                                $data, $transaction),
            'shipping_status' => $this->__getValue('shipping_status', $data, $transaction),
            'delivered_to' => $this->__getValue('delivered_to', $data, $transaction),
            'shipping_charges' => $this->__getValue('shipping_charges', $data, 
                $transaction, 0),
            'exchange_rate' => $this->__getValue('exchange_rate', $data, $transaction, 1),
            'selling_price_group_id' => $this->__getValue('selling_price_group_id', $data, $transaction),
            'pay_term_number' => $this->__getValue('pay_term_number', $data, $transaction),
            'pay_term_type' => $this->__getValue('pay_term_type', $data, $transaction),
            'is_recurring' => $this->__getValue('is_recurring', $data, $transaction, 0),
            'recur_interval' => $this->__getValue('recur_interval', $data, $transaction),
            'recur_interval_type' => $this->__getValue('recur_interval_type', $data, $transaction),
            'subscription_repeat_on' => $this->__getValue('subscription_repeat_on', $data, $transaction),
            'subscription_no' => $this->__getValue('subscription_no', $data, $transaction),
            'recur_repetitions' => $this->__getValue('recur_repetitions', $data, $transaction, 0),
            'order_addresses' => $this->__getValue('order_addresses', $data, $transaction),
            'rp_redeemed' => $this->__getValue('rp_redeemed', $data, $transaction, 0),
            'rp_redeemed_amount' => $this->__getValue('rp_redeemed_amount', $data, $transaction, 0),
            'is_created_from_api' => 1,
            'types_of_service_id' => $this->__getValue('types_of_service_id', $data, $transaction),
            'packing_charge' => $this->__getValue('packing_charge', $data, $transaction, 0),
            'packing_charge_type' => $this->__getValue('packing_charge_type', $data, $transaction),
            'service_custom_field_1' => $this->__getValue('service_custom_field_1', $data, $transaction),
            'service_custom_field_2' => $this->__getValue('service_custom_field_2', $data, $transaction),
            'service_custom_field_3' => $this->__getValue('service_custom_field_3', $data, $transaction),
            'service_custom_field_4' => $this->__getValue('service_custom_field_4', $data, $transaction),
            'round_off_amount' => $this->__getValue('round_off_amount', $data, $transaction),
            'res_table_id' => $this->__getValue('table_id', $data, $transaction, null, 'res_table_id'),
            'res_waiter_id' => $this->__getValue('service_staff_id', $data, $transaction, null, 'res_waiter_id'),
            'change_return' => $this->__getValue('change_return', $data, $transaction, 0),
            'change_return_id' => $this->__getValue('change_return_id', $data, $transaction, null),
            'is_quotation' => $this->__getValue('is_quotation', $data, $transaction, 0),
            'is_suspend' => $this->__getValue('is_suspend', $data, $transaction, 0)
        ];

        //Generate reference number
        if (!empty($formated_data['is_recurring'])) {
            //Update reference count
            $ref_count = $this->transactionUtil->setAndGetReferenceCount('subscription');
            $formated_data['subscription_no'] = $this->transactionUtil->generateReferenceNumber('subscription', $ref_count);
        }

        $sell_lines = [];
        $subtotal = 0;

        if (!empty($data['products'])) {
            foreach ($data['products'] as $product_data) {

                $sell_line = null;
                if (!empty($product_data['sell_line_id'])) {
                    $sell_line = TransactionSellLine::findOrFail($product_data['sell_line_id']);
                }

                $product_id = $this->__getValue('product_id', $product_data, $sell_line);
                $variation_id = $this->__getValue('variation_id', $product_data, $sell_line);
                $product = Product::where('business_id', $business_id)
                                ->with(['variations'])
                                ->findOrFail($product_id);

                $variation = $product->variations->where('id', $variation_id)->first();

                //Calculate line discount
                $unit_price =  $this->__getValue('unit_price', $product_data, $sell_line, $variation->sell_price_inc_tax, 'unit_price_before_discount');
                
                $discount_amount = $this->__getValue('discount_amount', $product_data, $sell_line, 0, 'line_discount_amount');

                $line_discount = $discount_amount;
                $line_discount_type = $this->__getValue('discount_type', $product_data, $sell_line, 'fixed', 'line_discount_type');

                if ($line_discount_type == 'percentage') {
                    $line_discount = $this->transactionUtil->calc_percentage($unit_price, $discount_amount);
                }
                $discounted_price = $unit_price - $line_discount;

                //calculate line tax
                $item_tax = 0;
                $unit_price_inc_tax = $discounted_price;
                $tax_id = $this->__getValue('tax_rate_id', $product_data, $sell_line, null, 'tax_id');
                if (!empty($tax_id)) {
                    $tax = TaxRate::where('business_id', $business_id)
                                ->findOrFail($tax_id);

                    $item_tax = $this->transactionUtil->calc_percentage($discounted_price, $tax->amount);
                    $unit_price_inc_tax += $item_tax;
                }

                $formated_sell_line = [
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'product_type' => $product->type,
                    'unit_price' => $unit_price,
                    'line_discount_type' => $line_discount_type,
                    'line_discount_amount' => $discount_amount,
                    'tax_id' => $tax_id,
                    'item_tax' => $item_tax,
                    'sell_line_note' => $this->__getValue('note', $product_data, $sell_line, null, 'sell_line_note'),
                    'enable_stock' => $product->enable_stock,
                    'quantity' => $this->__getValue('quantity', $product_data, 
                                        $sell_line, 0),
                    'product_unit_id' => $product->unit_id,
                    'sub_unit_id' => $this->__getValue('sub_unit_id', $product_data, 
                                        $sell_line),
                    'unit_price_inc_tax' => $unit_price_inc_tax
                ];
                if (!empty($sell_line)) {
                    $formated_sell_line['transaction_sell_lines_id'] = $sell_line->id;
                }

                if (($formated_sell_line['product_unit_id'] != $formated_sell_line['sub_unit_id']) && !empty($formated_sell_line['sub_unit_id']) ) {
                    $sub_unit = Unit::where('business_id', $business_id)
                                    ->findOrFail($formated_sell_line['sub_unit_id']);
                    $formated_sell_line['base_unit_multiplier'] = $sub_unit->base_unit_multiplier;
                } else {
                    $formated_sell_line['base_unit_multiplier'] = 1;
                }

                //Combo product
                if ($product->type == 'combo') {
                    $combo_variations = $this->productUtil->calculateComboDetails($location->id, $variation->combo_variations);
                    foreach ($combo_variations as $key => $value) {
                        $combo_variations[$key]['quantity'] = $combo_variations[$key]['qty_required'] * $formated_sell_line['quantity'] * $formated_sell_line['base_unit_multiplier'];
                    }
                    
                    $formated_sell_line['combo'] = $combo_variations;
                }

                $line_total = $unit_price_inc_tax * $formated_sell_line['quantity'];

                $sell_lines[] = $formated_sell_line;

                $subtotal += $line_total;
            }
        }

        $formated_data['products'] = $sell_lines;

        //calculate sell discount and tax
        $order_discount_amount = $this->__getValue('discount_amount', $data, $transaction, 0);
        $order_discount_type = $this->__getValue('discount_type', $data, $transaction, 'fixed');
        $order_discount = $order_discount_amount;
        if ($order_discount_type == 'percentage') {
            $order_discount = $this->transactionUtil->calc_percentage($subtotal, $order_discount_amount);
        }
        $discounted_total = $subtotal - $order_discount;

        //calculate line tax
        $order_tax = 0;
        $final_total = $discounted_total;
        $order_tax_id = $this->__getValue('tax_rate_id', $data, $transaction);
        if (!empty($order_tax_id)) {
            $tax = TaxRate::where('business_id', $business_id)
                        ->findOrFail($order_tax_id);

            $order_tax = $this->transactionUtil->calc_percentage($discounted_total, $tax->amount);
            $final_total += $order_tax;
        }

        $formated_data['discount_amount'] = $order_discount_amount;
        $formated_data['discount_type'] = $order_discount_type;
        $formated_data['tax_rate_id'] = $order_tax_id;
        $formated_data['tax_calculation_amount'] = $order_tax;

        $final_total += $formated_data['shipping_charges'];

        if (!empty($formated_data['packing_charge']) && !empty($formated_data['types_of_service_id'])) {
            $final_total += $formated_data['packing_charge'];
        }

        $formated_data['final_total'] = $final_total;

        $payments = [];
        if (!empty($data['payments'])) {
            foreach ($data['payments'] as $payment_data) {
                $transaction_payment =  null;
                if (!empty($payment_data['payment_id'])) {
                    $transaction_payment = TransactionPayment::findOrFail($payment_data['payment_id']);
                }
                $payment = [
                    'amount' => $this->__getValue('amount', $payment_data, $transaction_payment),
                    'method' => $this->__getValue('method', $payment_data, $transaction_payment),
                    'account_id' => $this->__getValue('account_id', $payment_data, $transaction_payment),
                    'card_number' => $this->__getValue('card_number', $payment_data, $transaction_payment),
                    'card_holder_name' => $this->__getValue('card_holder_name', $payment_data, $transaction_payment),
                    'card_transaction_number' => $this->__getValue('card_transaction_number', $payment_data, $transaction_payment),
                    'card_type' => $this->__getValue('card_type', $payment_data, $transaction_payment),
                    'card_month' => $this->__getValue('card_month', $payment_data, $transaction_payment),
                    'card_year' => $this->__getValue('card_year', $payment_data, $transaction_payment),
                    'card_security' => $this->__getValue('card_security', $payment_data, $transaction_payment),
                    'cheque_number' => $this->__getValue('cheque_number', $payment_data, $transaction_payment),
                    'bank_account_number' => $this->__getValue('bank_account_number', $payment_data, $transaction_payment),
                    'transaction_no_1' => $this->__getValue('transaction_no_1', $payment_data, $transaction_payment),
                    'transaction_no_2' => $this->__getValue('transaction_no_2', $payment_data, $transaction_payment),
                    'transaction_no_3' => $this->__getValue('transaction_no_3', $payment_data, $transaction_payment),
                    'note' => $this->__getValue('note', $payment_data, $transaction_payment),
                ];
                if (!empty($transaction_payment)) {
                    $payment['payment_id'] = $transaction_payment->id;
                }

                $payments[] = $payment;
            }

            $formated_data['payment'] = $payments;
        }
        return $formated_data;
    }

    /**
     * Delete Sell
     *
     * @urlParam sell required id of the sell to be deleted
     * 
     */
    public function destroy($id)
    {
        try {
            $user = Auth::user(); 
            $business_id = $user->business_id;
            //Begin transaction
            DB::beginTransaction();

            $output = $this->transactionUtil->deleteSale($business_id, $id);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output['success'] = false;
            $output['msg'] = trans("messages.something_went_wrong");
        }

        return $output;
    }

    /**
    * Update shipping status
    *
    * @bodyParam id int required id of the sale
    * @bodyParam shipping_status string ('ordered','picked', 'packed', 'shipped', 'delivered', 'cancelled') Example:ordered
    * @bodyParam delivered_to string Name of the consignee 
    */
    public function updateSellShippingStatus(Request $request)
    {
        try {
            $user = Auth::user(); 
            $business_id = $user->business_id;

            $sell_id = $request->input('id');
            $shipping_status = $request->input('shipping_status');
            $delivered_to = $request->input('delivered_to');
            $shipping_statuses = $this->transactionUtil->shipping_statuses();
            if (array_key_exists($shipping_status, $shipping_statuses)) {
                Transaction::where('business_id', $business_id)
                    ->where('id', $sell_id)
                    ->where('type', 'sell')
                    ->update(['shipping_status' => $shipping_status, 'delivered_to' => $delivered_to]);
            } else {
                return $this->otherExceptions('Invalid shipping status');
            }
            
            return $this->respond(['success' => 1,
                    'msg' => trans("lang_v1.updated_success")
                ]);
            
        } catch (\Exception $e) {
            return $this->otherExceptions($e);
        }
    }

    public function addSellReturn(Request $request)
    {
        try {
            $input = $request->except('_token');

            if (!empty($input['products'])) {
                $user = Auth::user(); 

                $business_id = $user->business_id;
        
                DB::beginTransaction();

                $output =  $this->transactionUtil->addSellReturn($input, $business_id, $user->id);
                
                DB::commit();
            }
        } catch(ModelNotFoundException $e){
            DB::rollback();
            $output = $this->modelNotFoundExceptionResult($e);
        } catch (\Exception $e) {
            DB::rollback();
            $output = $this->otherExceptions($e);
        }

        return $output;
    }

    public function listSellReturn()
    {
        $filters = request()->input();
        $user = Auth::user();
        $business_id = $user->business_id;

        $sell_id = request()->input('sell_id');
        $query = Transaction::where('business_id', $business_id)
                            ->where('type', 'sell_return')
                            ->where('status', 'final')
                            ->with(['payment_lines', 'return_parent_sell', 'return_parent_sell.sell_lines'])
                            ->select('transactions.*');

        $permitted_locations = $user->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (!empty($sell_id)) {
            $query->where('return_parent_id', $sell_id);
        }

        $perPage = !empty($filters['per_page']) ? $filters['per_page'] : $this->perPage;
        if ($perPage == -1) {
            $sell_returns = $query->get();
        } else {
            $sell_returns = $query->paginate($perPage);
            $sell_returns->appends(request()->query());
        }

        return SellResource::collection($sell_returns);
    }
}
