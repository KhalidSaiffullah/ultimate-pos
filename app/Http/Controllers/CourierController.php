<?php

namespace App\Http\Controllers;

use App\Business;
use League\Csv\Writer;
use Symfony\Component\HttpClient\HttpClient;
use Illuminate\Support\Str;
use App\Courier;
use App\EcourierOrder;
use App\Address;
use Illuminate\Http\Request;
use App\ShippingAddressMappingForCourier;
use App\CourierZoneMappings;
use PhpParser\Node\Stmt\ElseIf_;
use Yajra\DataTables\Facades\DataTables;
use DB;
use App\City;
use App\Contact;
use App\Zone;
use App\Store;
use App\Transaction;
use App\PathaoOrder;
use App\PathaoCallback;
use App\BusinessLocation;
use App\Courier_logs;
use App\LocationStore;
use App\Utils\TransactionUtil;
use App\TransactionSellLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;

class CourierController extends Controller
{

    /**
     * All Utils instance.
     *
     */
    protected $transactionUtil;
    /**
     * Constructor
     *
     * @param WoocommerceUtil $woocommerceUtil
     * @return void
     */
    public function __construct(TransactionUtil $transactionUtil)
    {
        $this->transactionUtil = $transactionUtil;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // $curl = curl_init('https://backoffice.ecourier.com.bd/api/city-list');
        // curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($curl, CURLOPT_POST, 1);
        // curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', 'API-SECRET: 09CMQ', 'API-KEY: gIpi', 'USER-ID: S4981']);
        // $response = json_decode(curl_exec($curl));
        // curl_close($curl);

        // foreach($response as $item){
        //     DB::table('ecourier_cities')->insert([
        //         'name' => $item->name,
        //         'value' => $item->value
        //     ]);
        // }

        // dd($response);

        // $ecourier_cities = DB::table('ecourier_cities')->get();
        // foreach($ecourier_cities as $city){
        //     $curl = curl_init('https://backoffice.ecourier.com.bd/api/thana-list');
        //     curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        //     curl_setopt($curl, CURLOPT_POST, 1);
        //     curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['city' => $city->value]));
        //     curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', 'API-SECRET: 09CMQ', 'API-KEY: gIpi', 'USER-ID: S4981']);
        //     $response = json_decode(curl_exec($curl));
        //     curl_close($curl);

        //     foreach($response->message as $item){
        //         DB::table('ecourier_thanas')->insert([
        //             'name' => $item->name,
        //             'value' => $item->value,
        //             'city' => $city->value
        //         ]);
        //     }
        // }
        // dd($ecourier_cities);

        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $couriers = Courier::where('business_id', $business_id)->select('id', 'name', 'client_email', 'client_id');

            return Datatables::of($couriers)
                ->addColumn(
                    'action',
                    '<button type="button" data-href="{{action(\'CourierController@edit\', [$id])}}" class="btn btn-xs btn-primary btn-modal btn-sync" data-container=".courier_edit_modal" style="margin-bottom: 2px;"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</button>
                    <br>
                    <button type="button" class="btn btn-xs btn-danger btn-modal btn-sync" onclick="deleteCourier(this,{{ $id }})" style="margin-bottom: 2px;"><i class="glyphicon glyphicon-trash"></i> Delete</button>
                    <br>
                    <button type="button" class="btn btn-xs btn-success btn-modal btn-sync" onclick="syncStore(this,{{ $id }})" style="margin-bottom: 2px;"><i class="glyphicon glyphicon-refresh"></i> Sync Stores</button>
                    '
                )
                ->removeColumn('id')
                // ->removeColumn('is_active')
                ->rawColumns([3])
                ->make(false);
        }

        return view('courier.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        return view('courier.add')->with(compact('business_id'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        if ($request->name == 'Pathao') {
            $courierExist = Courier::where('business_id', $request->business_id)->where('client_email', $request->client_email)->first();

            if ($courierExist) {
                return [
                    'success' => false,
                    'msg' => 'Client Email already exists'
                ];
            }

            try {
                $input = $request->only(['business_id', 'name', 'base_url', 'client_id', 'client_secret', 'client_email', 'client_password']);

                $courier = Courier::create($input);

                $response = $this->getAccessToken($courier->id);

                if ($response['success']) {
                    $this->syncLocation($courier->id);
                    $output = ['success' => true, 'msg' => 'Courier Created Successfully'];
                } else {
                    $output = ['success' => false, 'msg' => $response['msg']];
                    Courier::destroy($courier->id);
                }
            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __("messages.something_went_wrong")
                ];
            }
        } else if ($request->name == 'Ecourier') {
            $courierExist = Courier::where('business_id', $request->business_id)->where('client_id', $request->client_id)->first();

            if ($courierExist) {
                return [
                    'success' => false,
                    'msg' => 'User ID already exists'
                ];
            }

            try {
                $input = $request->only(['business_id', 'name', 'base_url', 'client_id', 'client_secret', 'client_password']);

                $courier = Courier::create($input);

                //$response = $this->checkEcourierApi($courier->id);

                if ($courier->id) {
                    $output = ['success' => true, 'msg' => 'Courier Created Successfully'];
                } else {
                    $output = ['success' => false, 'msg' => "Error while created"];
                    Courier::destroy($courier->id);
                }
            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __("messages.something_went_wrong")
                ];
            }
        }

        return $output;
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $courier = Courier::where('business_id', $business_id)->find($id);

        return view('courier.edit')->with(compact('courier'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');

        $courierExist = Courier::where('business_id', $business_id)->where('client_email', $request->client_email)->where('id', '!=', $id)->first();

        if ($courierExist) {
            return [
                'success' => false,
                'msg' => 'Client Email already exists'
            ];
        }

        try {
            $input = $request->only(['name', 'base_url', 'client_id', 'client_secret', 'client_email', 'client_password']);

            Courier::where('business_id', $business_id)
                ->where('id', $id)
                ->update($input);

            $response = $this->getAccessToken($id);

            if ($response['success']) {
                $output = ['success' => true, 'msg' => 'Courier Updated Successfully'];
            } else {
                $output = ['success' => false, 'msg' => $response['msg']];
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __("messages.something_went_wrong")
            ];
        }

        return $output;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            Courier::destroy($id);
            $output = ['success' => true, 'msg' => 'Courier Deleted Successfully'];
        } catch (\Exception $e) {
            $output = ['success' => false, 'msg' => __("messages.something_went_wrong")];
        }

        return $output;
    }

    public function syncLocation($id)
    {
        $response = $this->getCities($id);
        if (!$response) {
            $accessTokenResponse = $this->getAccessToken($id);

            if ($accessTokenResponse['success']) {
                $response = $this->getCities($id);
                if (!$response) {
                    return ['success' => false, 'msg' => 'Sync failed. Please try again'];
                }
            } else {
                return $accessTokenResponse;
            }
        }
        if ($response) {
            $cities = $response->data->data;
            foreach ($cities as $city) {
                $city_id = $this->syncCity($city);
                $response = $this->getZones($id, $city);
                if (!$response) {
                    return ['success' => false, 'msg' => 'Sync failed. Please try again'];
                }
                $zones = $response->data->data;
                foreach ($zones as $zone) {
                    $this->syncZone($zone, $city_id);
                }
            }
        }

        return ['success' => true, 'msg' => 'Synced successfully'];
    }

    public function syncStores($id)
    {
        $response = $this->getStores($id);

        if (!$response) {
            $accessTokenResponse = $this->getAccessToken($id);

            if ($accessTokenResponse['success']) {
                $response = $this->getStores($id);
                if (!$response) {
                    return ['success' => false, 'msg' => 'Sync failed. Please try again'];
                }
            } else {
                return $accessTokenResponse;
            }
        }
        if ($response) {
            $stores = $response->data->data;
            foreach ($stores as $store) {
                $this->syncStore($store, $id);
            }
        }

        $business_id = request()->session()->get('user.business_id');
        $stores = Store::where('courier_id', $id)->get();
        $locations = BusinessLocation::where('business_id', $business_id)->get();

        if ($stores && count($stores) > 0) {
            $defaultStore = $stores[0]->id;
            foreach ($stores as $store) {
                if ($store->is_default_store == 1) {
                    $defaultStore = $store->id;
                }
            }
            foreach ($locations as $location) {
                $existLocationStore = LocationStore::join('stores', 'stores.id', 'location_stores.store_id')
                    ->where('business_location_id', $location->id)
                    ->where('stores.courier_id', $id)
                    ->select('location_stores.*')
                    ->first();
                if (!$existLocationStore) {
                    $newLocationStore = new LocationStore;
                    $newLocationStore->store_id = $defaultStore;
                    $newLocationStore->business_location_id = $location->id;
                    $newLocationStore->created_at = now();
                    $newLocationStore->save();
                    $location->location_store_id = $newLocationStore->id;
                    $location->location_store = $newLocationStore->store_id;
                } else {
                    $location->location_store_id = $existLocationStore->id;
                    $location->location_store = $existLocationStore->store_id;
                }
            }
        }

        // return ['success' => true, 'msg' => 'Synced successfully'];
        return view('courier.stores')->with(compact('business_id', 'stores', 'locations'));
    }

    public function syncLocationStores(Request $request)
    {
        foreach ($request->location_store_id as $index => $location_store_id) {
            $locationStore = LocationStore::find($location_store_id);
            $locationStore->store_id = $request->store_id[$index];
            $locationStore->save();
        }
        return ['success' => true, 'msg' => 'Location Stores Updated Succesfully'];
    }

    public function createOrder(Request $request)
    {
        // return $request;
        if ($request->courier_id === "Manual") {
            $courier_name = "";
            if ($request->actual_store_id === "Manual") {
                $courier_name = "Manual";
            } else {
                $courierdata = Courier::where('id', $request->actual_store_id)->first();
                $courier_name = $courierdata->name;
            }
            // return $request;
            if ($courier_name === "Pathao") {
                $csvName = Str::uuid()->toString();
                $csvPath = storage_path("manualOrderCsv/{$csvName}.csv");
                $csv = Writer::createFromPath($csvPath, 'w+');
                $csv->insertOne(['ItemType(*)', 'StoreName(*)', 'MerchantOrderId', 'RecipientName(*)', 'RecipientPhone(*)', 'RecipientCity(*)', 'RecipientZone(*)', 'RecipientArea', 'RecipientAddress(*)', 'AmountToCollect(*)', 'ItemQuantity(*)', 'ItemWeight(*)', 'ItemDesc', 'SpecialInstruction']);
                $statusChange = false;
                if ($request->manualData === "Manual") {
                    $statusChange = true;

                }
                foreach (explode(',', $request->sells) as $sell_id) {
                    $response = $this->createOrderManually($sell_id, $courier_name, $csvPath, $request->actual_store_id, $request->store_id, $statusChange);

                }
                // return response()->json(['success' => true, 'csv_path' => $csvPath]);
                $csvData = file_get_contents($csvPath);

                $stringcsvpath = strval($csvPath);
                $output[] = [
                    'success' => 1,
                    'msg' => "Manual",
                    'csv_path' => $csvData
                ];
            } else if ($courier_name === "Ecourier") {
                $output[] = [
                    'success' => 0,
                    'msg' => "eCourier comming soon............."
                ];
                return $output;

                $csvName = Str::uuid()->toString();
                $csvPath = storage_path("app/{$csvName}.csv");
                foreach (explode(',', $request->sells) as $sell_id) {
                    $response = $this->createOrderManually($sell_id, $courier_name, $csvName);

                }
            } else {
                $csvName = Str::uuid()->toString();
                $csvPath = storage_path("manualOrderCsv/{$csvName}.csv");
                $csv = Writer::createFromPath($csvPath, 'w+');
                $csv->insertOne(['Parcel Type', 'Merchant Name', 'Invoice ID', 'Customer Name', 'Phone Number', 'City', 'Zone', 'Area', 'Address', 'Due', 'Item Count', 'Weight (kg)', 'Items', 'Notes']);
                $statusChange = false;
                if ($request->manualData === "Manual") {
                    $statusChange = true;

                }
                foreach (explode(',', $request->sells) as $sell_id) {
                    $response = $this->createOrderManually($sell_id, $courier_name, $csvPath, $request->actual_store_id, $request->store_id, $statusChange);

                }
                // return response()->json(['success' => true, 'csv_path' => $csvPath]);
                $csvData = file_get_contents($csvPath);

                $stringcsvpath = strval($csvPath);
                $output[] = [
                    'success' => 1,
                    'msg' => "Manual",
                    'csv_path' => $csvData
                ];

            }
            return $output;


        } else {
            $courierdata = Courier::where('id', $request->courier_id)->first();
            foreach (explode(',', $request->sells) as $sell_id) {
                if ($courierdata) {
                    $courier_name = $courierdata->name;
                    $request->sells;
                    if ($courier_name === 'Pathao') {
                        $response = $this->createSingleOrder($sell_id, $request->courier_id, $request->store_id);
                        // return $response;
                        if ($response) {
                            $output[] = $response;
                        } else {
                            $accessTokenResponse = $this->getAccessToken($request->courier_id);
                            if ($accessTokenResponse['success']) {
                                $response = $this->createSingleOrder($sell_id, $request->courier_id, $request->store_id);
                                if ($response) {
                                    $output[] = $response;
                                } else {
                                    $output[] = [
                                        'success' => 0,
                                        'msg' => 'Something went wrong'
                                    ];
                                }
                            } else {
                                $output[] = [
                                    'success' => 0,
                                    'msg' => $accessTokenResponse['msg']
                                ];
                            }
                        }
                    } elseif ($courier_name === 'Ecourier') {
                        $response = $this->createOrderEcourier($sell_id, $courierdata);
                        // return $response;
                        if ($response) {
                            $output[] = $response;
                        } else {
                            $output[] = [
                                'success' => 0,
                                'msg' => 'Something went wrong'
                            ];
                        }
                    }

                } else {

                }

            }

            return $output;

        }

    }

    private function createOrderManually($sell_id, $couriername, $csvPath, $courier_id, $store_id, $statusChange)
    {
        $transaction = Transaction::leftJoin('transaction_sell_lines as tsl', 'tsl.transaction_id', 'transactions.id')
            ->leftJoin('products', 'products.id', 'tsl.product_id')
            ->select('transactions.*', DB::raw('SUM(tsl.quantity) as item_quantity'), DB::raw('SUM(products.weight) as item_weight'), DB::raw("GROUP_CONCAT(products.name,'-',ROUND(tsl.quantity) separator ', ') as item_desc"))
            ->find($sell_id);
        $business_id = request()->session()->get('user.business_id');
        $business = Business::findOrFail($business_id);
        $hasPathaoOrder = PathaoOrder::where('transaction_id', $sell_id)->first();
        $hascourierOrder = EcourierOrder::where('transaction_id', $sell_id)->first();
        // return $hasPathaoOrder;
        if ($hasPathaoOrder) {
            $msg = $hasPathaoOrder->merchant_order_id . ' - Order already exists on pathao (Consignment ID - ' . $hasPathaoOrder->consignment_id . ')';
            $this->courierlog($transaction->invoice_no, $hasPathaoOrder->consignment_id, 0, 'Failed', $msg, $transaction->created_by, $business_id, "Pathao");

            return [
                'success' => 0,
                'msg' => $hasPathaoOrder->merchant_order_id . ' - Order already exists on pathao (Consignment ID - ' . $hasPathaoOrder->consignment_id . ')'
            ];
        }
        if ($hascourierOrder) {
            $msg = $hascourierOrder->transaction_id . ' - Order already exists on ecourier (Tracking ID - ' . $hascourierOrder->tracking_id . ')';
            $this->courierlog($transaction->invoice_no, $hascourierOrder->tracking_id, 0, 'Failed', $msg, $transaction->created_by, $business_id, "Ecourier");

            return [
                'success' => 0,
                'msg' => $hascourierOrder->transaction_id . ' - Order already exists on ecourier (Tracking ID - ' . $hascourierOrder->tracking_id . ')'
            ];
        }

        if ($transaction) {
            if ($couriername === "Ecourier") {
                $shipping_address = Address::where('addresses.id', $transaction->shipping_address_id)
                    ->leftJoin('ecourier_address_mappings', 'addresses.default_zone', '=', 'ecourier_address_mappings.default_mapping_id')

                    ->select('addresses.*', 'ecourier_address_mappings.*') // Adjust the columns as needed
                    ->first();

                $billing_address = Address::where('addresses.id', $transaction->billing_address_id)
                    ->leftJoin('ecourier_address_mappings', 'addresses.default_zone', '=', 'ecourier_address_mappings.default_mapping_id')
                    ->select('addresses.*', 'ecourier_address_mappings.*') // Adjust the columns as needed
                    ->first();


                $transaction->shipping_status = 'shipped';
                $transaction->save();
                $this->courierlog($transaction->invoice_no, "Manual", null, 'Success', 'Manual', $transaction->created_by, $business_id, "Manual");
            } else if ($couriername === "Pathao") {
                $store_name = "";
                if ($store_id == 0) {
                    $store = LocationStore::join('stores', 'stores.id', 'location_stores.store_id')
                        ->where('business_location_id', $transaction->location_id)
                        ->where('stores.courier_id', $courier_id)
                        ->select('stores.*')
                        ->first();
                    if ($store) {
                        $store_id = $store->store_id;
                        $store_name = $store->name;
                    } else {
                        return [
                            'success' => 0,
                            'msg' => $transaction->invoice_no . ' - No default store for this business location'
                        ];
                    }
                } else {
                    $store = Store::where('store_id', $store_id)->first();
                    $store_name = $store->name;
                }


                $csv = Writer::createFromPath($csvPath, 'a+');

                $shipping_address = Address::where('addresses.id', $transaction->shipping_address_id)
                    ->leftJoin('pathao_courier_address_mappings', 'addresses.default_zone', '=', 'pathao_courier_address_mappings.default_mapping_id')
                    ->select('addresses.*', 'pathao_courier_address_mappings.*') // Adjust the columns as needed
                    ->first();
                $billing_address = Address::where('addresses.id', $transaction->billing_address_id)
                    ->leftJoin('pathao_courier_address_mappings', 'addresses.default_zone', '=', 'pathao_courier_address_mappings.default_mapping_id')
                    ->select('addresses.*', 'pathao_courier_address_mappings.*') // Adjust the columns as needed
                    ->first();
                $number = $shipping_address->number !== '' ? intval($shipping_address->number) : intval($billing_address->number);
                $last11Digits = $number % 100000000000;
                $rowData = [
                    'parcel',       // Parcel Type
                    $store_name,     // Merchant Name
                    $transaction->invoice_no,       // Invoice ID
                    $shipping_address->name,     // Customer Name
                    $last11Digits, // Phone Number
                    $shipping_address->pathao_city_name,         // City
                    $shipping_address->pathao_zone_name,         // Zone
                    '',         // Area
                    $shipping_address->address,  // Address
                    intval(ceil($transaction->final_total - $transaction->total_paid)),        // Due
                    intval($transaction->item_quantity),            // Item Count
                    '0.2',         // Weight (kg)
                    ' ', // Items
                    ' ' // Notes
                ];

                // Add the example row to the CSV
                $csv->insertOne($rowData);
                if ($statusChange) {
                    $transaction->shipping_status = 'shipped';
                    $transaction->save();
                } else {
                    $transaction->save();
                }
                $this->courierlog($transaction->invoice_no, "Manual", null, 'Success', 'Manual', $transaction->created_by, $business_id, "Manual");
                return [
                    'success' => 1,
                    'msg' => "Order Create Successfully"
                ];
            } else {

                $csv = Writer::createFromPath($csvPath, 'a+');

                $shipping_address = Address::where('addresses.id', $transaction->shipping_address_id)
                    ->leftJoin('courier_zone_mappings', 'addresses.default_zone', '=', 'courier_zone_mappings.id')
                    ->select('addresses.*', 'courier_zone_mappings.*') // Adjust the columns as needed
                    ->first();
                $billing_address = Address::where('addresses.id', $transaction->billing_address_id)
                    ->leftJoin('courier_zone_mappings', 'addresses.default_zone', '=', 'courier_zone_mappings.id')
                    ->select('addresses.*', 'courier_zone_mappings.*') // Adjust the columns as needed
                    ->first();

                $rowData = [
                    'Parcel',       // Parcel Type
                    $business->name,     // Merchant Name
                    $transaction->invoice_no,       // Invoice ID
                    $shipping_address->name,     // Customer Name
                    $shipping_address->number !== '' ? $shipping_address->number : $billing_address->number, // Phone Number
                    $shipping_address->city_name,         // City
                    $shipping_address->zone_name,         // Zone
                    $shipping_address->area_name,         // Area
                    $shipping_address->address,  // Address
                    intval(ceil($transaction->final_total - $transaction->total_paid)),        // Due
                    intval($transaction->item_quantity),            // Item Count
                    '0.2',         // Weight (kg)
                    ' ', // Items
                    ' ' // Notes
                ];

                // Add the example row to the CSV
                $csv->insertOne($rowData);
                if ($statusChange) {
                    $transaction->shipping_status = 'shipped';
                    $transaction->save();
                } else {
                    $transaction->save();
                }
                $this->courierlog($transaction->invoice_no, "Manual", null, 'Success', 'Manual', $transaction->created_by, $business_id, "Manual");
                return [
                    'success' => 1,
                    'msg' => "Order Create Successfully"
                ];
            }

            // return $headers;



        }
    }

    private function createOrderEcourier($sell_id, $courier)
    {
        $transaction = Transaction::leftJoin('transaction_sell_lines as tsl', 'tsl.transaction_id', 'transactions.id')
            ->leftJoin('products', 'products.id', 'tsl.product_id')
            ->select('transactions.*', DB::raw('SUM(tsl.quantity) as item_quantity'), DB::raw('SUM(products.weight) as item_weight'), DB::raw("GROUP_CONCAT(products.name,'-',ROUND(tsl.quantity) separator ', ') as item_desc"))
            ->find($sell_id);
        $business_id = request()->session()->get('user.business_id');
        $hasPathaoOrder = PathaoOrder::where('transaction_id', $sell_id)->first();
        $hascourierOrder = EcourierOrder::where('transaction_id', $sell_id)->first();
        // return $hasPathaoOrder;
        if ($hasPathaoOrder) {
            $msg = $hasPathaoOrder->merchant_order_id . ' - Order already exists on pathao (Consignment ID - ' . $hasPathaoOrder->consignment_id . ')';
            $this->courierlog($transaction->invoice_no, $hasPathaoOrder->consignment_id, 0, 'Failed', $msg, $transaction->created_by, $business_id, "Pathao");

            return [
                'success' => 0,
                'msg' => $hasPathaoOrder->merchant_order_id . ' - Order already exists on pathao (Consignment ID - ' . $hasPathaoOrder->consignment_id . ')'
            ];
        }
        if ($hascourierOrder) {
            $msg = $hascourierOrder->transaction_id . ' - Order already exists on ecourier (Tracking ID - ' . $hascourierOrder->tracking_id . ')';
            $this->courierlog($transaction->invoice_no, $hascourierOrder->tracking_id, 0, 'Failed', $msg, $transaction->created_by, $business_id, "Ecourier");

            return [
                'success' => 0,
                'msg' => $hascourierOrder->transaction_id . ' - Order already exists on ecourier (Tracking ID - ' . $hascourierOrder->tracking_id . ')'
            ];
        }

        if ($transaction) {
            $shipping_address = Address::where('addresses.id', $transaction->shipping_address_id)
                ->leftJoin('ecourier_address_mappings', 'addresses.default_zone', '=', 'ecourier_address_mappings.default_mapping_id')

                ->select('addresses.*', 'ecourier_address_mappings.*') // Adjust the columns as needed
                ->first();

            $billing_address = Address::where('addresses.id', $transaction->billing_address_id)
                ->leftJoin('ecourier_address_mappings', 'addresses.default_zone', '=', 'ecourier_address_mappings.default_mapping_id')
                ->select('addresses.*', 'ecourier_address_mappings.*') // Adjust the columns as needed
                ->first();

            // return $headers;
            $body = [];
            if ($shipping_address->ecourier_city_name === 'Dhaka') {
                $body = [
                    "recipient_name" => $shipping_address->name,
                    "recipient_mobile" => $shipping_address->number !== '' ? $shipping_address->number : $billing_address->number,
                    "recipient_city" => $shipping_address->ecourier_city_name,
                    "recipient_area" => $shipping_address->ecourier_area_name,
                    "recipient_thana" => $shipping_address->ecourier_thana_name,
                    "recipient_address" => $shipping_address->address,
                    "recipient_zip" => $shipping_address->ecourier_zip_code,
                    "package_code" => "#2443",
                    "product_price" => intval(ceil($transaction->final_total - $transaction->total_paid)),
                    "payment_method" => "COD",
                    "parcel_type" => "BOX", // Default: BOX
                    "comments" => "this is a test order from api", // Default: null
                    "product_id" => strval($transaction->invoice_no)
                ];
            } else {
                $body = [
                    "recipient_name" => $shipping_address->name,
                    "recipient_mobile" => $shipping_address->number !== '' ? $shipping_address->number : $billing_address->number,
                    "recipient_city" => $shipping_address->ecourier_city_name,
                    "recipient_area" => $shipping_address->ecourier_area_name,
                    "recipient_thana" => $shipping_address->ecourier_thana_name,
                    "recipient_address" => $shipping_address->address,
                    "recipient_zip" => $shipping_address->ecourier_zip_code,
                    "package_code" => "#2641",
                    "product_price" => intval(ceil($transaction->final_total - $transaction->total_paid)),
                    "payment_method" => "COD",
                    "parcel_type" => "BOX", // Default: BOX
                    "comments" => "this is a test order from api", // Default: null
                    "product_id" => strval($transaction->invoice_no)

                ];
            }
            $data = json_encode($body);
            // return $data;
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://backoffice.ecourier.com.bd/api/order-place',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_HTTPHEADER => [
                    'USER-ID: ' . $courier->client_id,
                    'API-KEY: ' . $courier->client_secret,
                    'API-SECRET: ' . $courier->client_password,
                    'Content-Type: application/json',
                ],
            ]);

            $response = curl_exec($curl);
            curl_close($curl);
            $responseData = json_decode($response);
            if ($responseData && $responseData->success) {
                EcourierOrder::create([
                    'transaction_id' => $sell_id, // Replace with the actual transaction ID
                    'order_status' => 'Initiated',
                    'tracking_id' => $responseData->ID,
                    'order_json' => json_encode($responseData),
                ]);
                $transaction->shipping_status = 'shipped';
                $transaction->save();
                $this->courierlog($transaction->invoice_no, $responseData->ID, null, 'Success', 'Initiated', $transaction->created_by, $business_id, "eCourier");
                return [
                    'success' => $responseData->success,
                    'msg' => $transaction->invoice_no . ' - ' . $responseData->message
                ];

            } else {
                EcourierOrder::create([
                    'transaction_id' => $sell_id,
                    'order_json' => json_encode($responseData),
                ]);
            }
        }
        return null;
    }
    private static function getAccessToken($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $courier = Courier::where('business_id', $business_id)->find($id);

        try {
            $data = ['client_id' => $courier->client_id, 'client_secret' => $courier->client_secret, 'username' => $courier->client_email, 'password' => $courier->client_password, 'grant_type' => 'password'];
            $curl = curl_init('https://' . $courier->base_url . '/aladdin/api/v1/issue-token');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
            $response = json_decode(curl_exec($curl));
            curl_close($curl);
        } catch (\Exception $e) {
            $response = $e;
        }

        if ($response) {
            if (property_exists($response, 'access_token')) {
                $access_token = $response->token_type . ' ' . $response->access_token;
                $courier->access_token = $access_token;
                $courier->save();

                return ['success' => true, 'access_token' => $access_token];
            } else {
                return ['success' => false, 'msg' => $response->message];
            }
        }
        return ['success' => false, 'msg' => 'Invalid Courier Data'];
    }

    public function checkEcourierApi($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $courier = Courier::where('business_id', $business_id)->find($id);
        try {
            $curl = curl_init('https://' . $courier->base_url . '/thana-list');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['city' => 'Dhaka']));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', 'API-SECRET: ' . $courier->client_secret, 'API-KEY: ' . $courier->client_password, 'USER-ID: ' . $courier->client_id]);
            $response = json_decode(curl_exec($curl));
            return $response;

            curl_close($curl);
        } catch (\Exception $e) {
            $response = $e;
        }

        if ($response) {
            if (property_exists($response, 'success') && $response->success == true) {
                return ['success' => true];
            } else {
                return ['success' => false, 'msg' => $response->message];
            }
        }
        return ['success' => false, 'msg' => 'Invalid Courier Data'];
    }

    private static function getStores($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $courier = Courier::where('business_id', $business_id)->find($id);

        try {
            $curl = curl_init('https://' . $courier->base_url . '/aladdin/api/v1/stores');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', 'Authorization: ' . $courier->access_token]);
            $response = json_decode(curl_exec($curl));

            curl_close($curl);
        } catch (\Exception $e) {
            $response = $e;
        }

        return $response;
    }

    private static function getCities($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $courier = Courier::where('business_id', $business_id)->find($id);

        try {
            $curl = curl_init('https://' . $courier->base_url . '/aladdin/api/v1/countries/1/city-list');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', 'Authorization: ' . $courier->access_token]);
            $response = json_decode(curl_exec($curl));
            curl_close($curl);
        } catch (\Exception $e) {
            $response = $e;
        }

        return $response;
    }

    private static function getZones($id, $city)
    {
        $business_id = request()->session()->get('user.business_id');
        $courier = Courier::where('business_id', $business_id)->find($id);

        try {
            $curl = curl_init('https://' . $courier->base_url . '/aladdin/api/v1/cities/' . $city->city_id . '/zone-list');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', 'Authorization: ' . $courier->access_token]);
            $response = json_decode(curl_exec($curl));
            curl_close($curl);
        } catch (\Exception $e) {
            $response = $e;
        }

        return $response;
    }

    private function createSingleOrder($sell_id, $courier_id, $store_id)
    {
        $transaction = Transaction::leftJoin('transaction_sell_lines as tsl', 'tsl.transaction_id', 'transactions.id')
            ->leftJoin('products', 'products.id', 'tsl.product_id')
            ->select('transactions.*', DB::raw('SUM(tsl.quantity) as item_quantity'), DB::raw('SUM(products.weight) as item_weight'), DB::raw("GROUP_CONCAT(products.name,'-',ROUND(tsl.quantity) separator ', ') as item_desc"))
            ->find($sell_id);
        $business_id = request()->session()->get('user.business_id');



        $hasPathaoOrder = PathaoOrder::where('transaction_id', $sell_id)->first();
        $hascourierOrder = EcourierOrder::where('transaction_id', $sell_id)->first();

        if ($hasPathaoOrder) {
            $msg = $hasPathaoOrder->merchant_order_id . ' - Order already exists on pathao (Consignment ID - ' . $hasPathaoOrder->consignment_id . ')';
            $this->courierlog($transaction->invoice_no, $hasPathaoOrder->consignment_id, $store_id, 'Failed', $msg, $transaction->created_by, $business_id, "Pathao");

            return [
                'success' => 0,
                'msg' => $hasPathaoOrder->merchant_order_id . ' - Order already exists on pathao (Consignment ID - ' . $hasPathaoOrder->consignment_id . ')'
            ];
        }
        if ($hascourierOrder) {
            $msg = $hascourierOrder->transaction_id . ' - Order already exists on ecourier (Tracking ID - ' . $hascourierOrder->tracking_id . ')';
            $this->courierlog($transaction->invoice_no, $hascourierOrder->tracking_id, 0, 'Failed', $msg, $transaction->created_by, $business_id, "Ecourier");

            return [
                'success' => 0,
                'msg' => $hascourierOrder->transaction_id . ' - Order already exists on ecourier (Tracking ID - ' . $hascourierOrder->tracking_id . ')'
            ];
        }

        $shipping_address = Address::where('addresses.id', $transaction->shipping_address_id)
            ->leftJoin('pathao_courier_address_mappings', 'addresses.default_zone', '=', 'pathao_courier_address_mappings.default_mapping_id')
            ->select('addresses.*', 'pathao_courier_address_mappings.*') // Adjust the columns as needed
            ->first();
        $billing_address = Address::where('addresses.id', $transaction->billing_address_id)
            ->leftJoin('pathao_courier_address_mappings', 'addresses.default_zone', '=', 'pathao_courier_address_mappings.default_mapping_id')
            ->select('addresses.*', 'pathao_courier_address_mappings.*') // Adjust the columns as needed
            ->first();
        $contactInfo = Contact::where('id', $transaction->contact_id)->first();
        $courier = Courier::find($courier_id);
        if ($store_id == 0) {
            $store = LocationStore::join('stores', 'stores.id', 'location_stores.store_id')
                ->where('business_location_id', $transaction->location_id)
                ->where('stores.courier_id', $courier_id)
                ->select('stores.*')
                ->first();
            if ($store) {
                $store_id = $store->store_id;
            } else {
                return [
                    'success' => 0,
                    'msg' => $transaction->invoice_no . ' - No default store for this business location'
                ];
            }
        } else {
            $store = Store::where('store_id', $store_id)->first();
        }
        try {

            $data = [
                'store_id' => intval($store_id),
                'merchant_order_id' => $transaction->invoice_no,
                'recipient_name' => $shipping_address->name,
                //'recipient_phone' => $shipping_address->number !== '' ? $shipping_address->number : $billing_address->number,
                'recipient_phone' => (($shipping_address->number !== '' && strpos($shipping_address->number, '+88') === 0) ? substr($shipping_address->number, strlen('+88')) : $shipping_address->number) ?: (($billing_address->number !== '' && strpos($billing_address->number, '+88') === 0) ? substr($billing_address->number, strlen('+88')) : $billing_address->number),
                'recipient_address' => $shipping_address->address,
                'recipient_city' => $shipping_address->pathao_city_id,
                'recipient_zone' => $shipping_address->pathao_zone_id,
                // 'recipient_area' => null,
                'delivery_type' => 48,
                // 48 for Normal delivery, 12 for On demand Delivery
                'item_type' => 2,
                // 1 for Document, 2 for Parcel
                'special_instruction' => $transaction->additional_notes,
                'item_quantity' => intval($transaction->item_quantity),
                // 'item_weight' => $transaction->item_weight,
                'item_weight' => 0.2,
                'amount_to_collect' => intval(ceil($transaction->final_total - $transaction->total_paid)),
                'item_description' => $transaction->item_desc,
            ];
            // return json_encode($data);
            $curl = curl_init('https://' . $courier->base_url . '/aladdin/api/v1/orders');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', 'Authorization: ' . $courier->access_token]);
            $response = json_decode(curl_exec($curl));
            curl_close($curl);
        } catch (\Exception $e) {
            $response = $e;
        }
        if (!$response) {
            return $response;
        }
        $response->invoice_no = $transaction->invoice_no;
        $response->success = 1;
        if ($response->code != 200) {
            $response->success = 0;
            $message = '';
            $errors = [];
            if (is_array($response->errors) || is_object($response->errors)) {
                foreach ($response->errors as $error) {
                    foreach ($error as $errorItem) {
                        $errors[] = $errorItem;
                    }
                }
            } else {
                $errors[] = $response->errors;
            }
            foreach ($errors as $key => $error) {
                $message .= $error;
                if (count($errors) > $key + 1) {
                    $message .= '<br>';
                }
            }
            $response->message = $message;
        } else {
            $pathaoOrder = new PathaoOrder;
            $pathaoOrder->store_id = $store->id;
            $pathaoOrder->consignment_id = $response->data->consignment_id;
            $pathaoOrder->merchant_order_id = $transaction->invoice_no;
            $pathaoOrder->transaction_id = $transaction->id;
            $pathaoOrder->order_status = $response->data->order_status;
            $pathaoOrder->delivery_fee = $response->data->delivery_fee;
            $pathaoOrder->save();
            $transaction->shipping_status = 'shipped';
            $transaction->save();
            $response->message = $response->message . ' (Consignment ID - ' . $response->data->consignment_id . ')';

            $this->courierlog($transaction->invoice_no, $response->data->consignment_id, $store_id, 'Success', $response->message, $transaction->created_by, $business_id, "Pathao");

        }

        return [
            'success' => $response->success,
            'msg' => $transaction->invoice_no . ' - ' . $response->message
        ];
    }

    public function getTransactionData(Request $request)
    {
        $invoiceNo = $request->input('invoice_no');
        $business_id = request()->session()->get('user.business_id');


        $transactionData = Transaction::where('invoice_no', $invoiceNo)
            ->where('business_id', $business_id)
            ->value('id');
        if ($transactionData) {
            return response()->json(['id' => $transactionData]);
        } else {
            return response()->json(['id' => null]);
        }
    }
    public function getCounts(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');
        $currentDate = Carbon::now();

        $today = $currentDate->toDateString();
        $successCount = Courier_logs::where('status', 'Success')->where('business_id', $business_id)->whereDate('created_at', $currentDate)->count();
        $failureCount = Courier_logs::where('status', 'Failed')->where('business_id', $business_id)->whereDate('created_at', $currentDate)->count();

        return response()->json(['successCount' => $successCount, 'failureCount' => $failureCount]);
    }



    private static function syncCity($city)
    {
        $cityExist = City::where('pathao_city_id', $city->city_id)->first();
        if (!$cityExist) {
            try {
                $newCity = new City;
                $newCity->name = $city->city_name;
                $newCity->pathao_city_id = $city->city_id;
                $newCity->created_at = now();
                $newCity->save();

                return $newCity->id;
            } catch (\Exception $e) {
                return 0;
            }
        } else {
            if ($cityExist->name != $city->city_name) {
                $cityExist->name = $city->city_name;
                $cityExist->updated_at = now();
                $cityExist->save();
            }
            return $cityExist->id;
        }
    }

    private static function syncZone($zone, $city_id)
    {
        $zoneExist = Zone::where('pathao_zone_id', $zone->zone_id)->first();
        if (!$zoneExist) {
            try {
                $newZone = new Zone;
                $newZone->name = $zone->zone_name;
                $newZone->city_id = $city_id;
                $newZone->pathao_zone_id = $zone->zone_id;
                $newZone->created_at = now();
                $newZone->save();
                return 1;
            } catch (\Exception $e) {
                return 0;
            }
        } else {
            if ($zoneExist->name != $zone->zone_name) {
                $zoneExist->name = $zone->zone_name;
                $zoneExist->updated_at = now();
                $zoneExist->save();

                return 2;
            }
            return 3;
        }
    }

    private static function syncStore($store, $courier_id)
    {
        $storeExist = Store::where('store_id', $store->store_id)->where('courier_id', $courier_id)->first();

        if (!$storeExist) {
            try {
                $newStore = new Store;
                $newStore->courier_id = $courier_id;
                $newStore->store_id = $store->store_id;
                $newStore->name = $store->store_name;
                $newStore->address = $store->store_address;
                $newStore->city_id = $store->city_id;
                $newStore->hub_id = $store->hub_id;
                $newStore->is_default_store = $store->is_default_store;
                $newStore->is_default_return_store = $store->is_default_return_store;
                $newStore->created_at = now();
                $newStore->save();
                return 1;
            } catch (\Exception $e) {


                return 0;
            }
        } else {
            $storeExist->courier_id = $courier_id;
            $storeExist->name = $store->store_name;
            $storeExist->address = $store->store_address;
            $storeExist->city_id = $store->city_id;
            $storeExist->hub_id = $store->hub_id;
            $storeExist->is_default_store = $store->is_default_store;
            $storeExist->is_default_return_store = $store->is_default_return_store;
            $storeExist->updated_at = now();
            $storeExist->save();
            return 2;
        }
    }

    public function syncStatus(Request $request)
    {
        $msg = '';
        if ($request->header("x-pathao-signature") == 'sha1=7d38cdd689735b008b3c702edd92eea23791c5f6') {
            $pathaoOrder = PathaoOrder::where('consignment_id', $request->consignment_id)->first();
            if ($pathaoOrder) {
                $pathaoOrder->order_status = $request->order_status;
                $pathaoOrder->save();
                $transaction = Transaction::find($pathaoOrder->transaction_id);
                // return $transaction;
                if ($transaction) {
                    $pathao_order_status = [

                        "Picked" => "picked",

                        "delivered" => "delivered",
                        "Pickup Cancelled" => "cancelled",

                        "Return" => "cancelled",
                        "Delivery_Failed" => "cancelled",
                        "On_Hold" => "picked",


                        'cancelled' => "cancelled"
                    ];


                    $pathao_order_status_for_woo = [
                        // "Pickup_Requested",
                        // "Assigned_for_Pickup",
                        // "Pickup_Failed",
                        // "Pickup_Cancelled",
                        // "At_the_Sorting_HUB",
                        // "In_Transit",
                        // "Received_at_Last_Mile_HUB",
                        // "Assigned_for_Delivery",
                        // "Partial_Delivery",
                        "On_Hold",
                        // "Payment_Invoice",
                    ];




                    if (in_array($request->order_status, array_keys($pathao_order_status))) {
                        $newStatus = $pathao_order_status[$request->order_status];
                        $transaction->shipping_status = $newStatus;
                        $transaction->save();


                        $updateResult = $this->transactionUtil->updateWooOrder($request->order_status, $transaction->woocommerce_order_id, $transaction->business_id);

                        $msg = 'Status updated to ' . $request->order_status;
                    } elseif (in_array($request->order_status, $pathao_order_status_for_woo)) {

                        $updateResult = $this->transactionUtil->updateWooOrder($request->order_status, $transaction->woocommerce_order_id, $transaction->business_id);
                    } else {
                        $msg = 'Status is not ' . $request->order_status;
                    }
                    if ($request->order_status == 'Return') {

                        $invoice_no = $transaction->invoice_no;
                        $transaction_date = $transaction->transaction_date;
                        $discount_type = $transaction->discount_type;
                        $discount_amount = $transaction->discount_amount;
                        $tax_id = $transaction->tax_id;
                        $tax_amount = $transaction->tax_amount;
                        $tax_percent = 0;
                        $transaction_id = $transaction->id;

                        $productdata = TransactionSellLine::where('transaction_id', $transaction_id)->select('quantity', 'unit_price_inc_tax', 'id')->get();


                        $products = [];

                        foreach ($productdata as $product) {
                            $products[] = [
                                'quantity' => $product->quantity,
                                'unit_price_inc_tax' => $product->unit_price_inc_tax,
                                'sell_line_id' => $product->id,
                            ];
                        }



                        $input = [
                            'transaction_id' => $transaction_id,
                            'invoice_no' => $invoice_no,
                            'transaction_date' => $transaction_date,
                            'products' => $products,
                            'discount_type' => $discount_type,
                            'discount_amount' => $discount_amount,
                            'tax_id' => $tax_id,
                            'tax_amount' => $tax_amount,
                            'tax_percent' => $tax_percent,
                        ];


                        // return $input;

                        // $input = json_encode($input);

                        $user_id = $transaction->created_by;

                        $updatereturn = $this->transactionUtil->addSellReturn($input, $transaction->business_id, $user_id, false);
                        // return $updatereturn;
                    } else if ($request->order_status == 'Pickup Cancelled') {

                        $newStatus = $pathao_order_status[$request->order_status];
                        $transaction->shipping_status = $newStatus;
                        $transaction->save();

                    }

                } else {
                    $msg = 'Transaction not found';
                }
            } else {
                $msg = 'Pathao order not found';
            }
        } else {
            $msg = 'Wrong sha1';
        }

        $pathaoCallback = new PathaoCallback;
        $pathaoCallback->x_pathao_signature = $request->header("x-pathao-signature");
        $pathaoCallback->consignment_id = $request->consignment_id;
        $pathaoCallback->tracking_number = $request->tracking_number;
        $pathaoCallback->status = $request->order_status;
        $pathaoCallback->comments = json_encode($request->all());
        $pathaoCallback->merchant_booking_id = $request->merchant_order_id;
        $pathaoCallback->timestamp = $request->updated_at;
        $pathaoCallback->update = $msg;
        $pathaoCallback->save();

        return $msg;
    }

    public function deletePathaoOrder($id)
    {
        $pathaoOrder = PathaoOrder::find($id);
        $mainorder = Transaction::where('invoice_no', $pathaoOrder->merchant_order_id)->first();
        if ($pathaoOrder) {
            if ($pathaoOrder->delete()) {
                $mainorder->shipping_status = "packed";
                $mainorder->save();
                return 1;
            }
        }
        return 0;
    }
    public function deleteEcourierOrder($id)
    {
        $ecourierOrder = EcourierOrder::where('transaction_id', $id)->first();
        $mainorder = Transaction::where('id', $ecourierOrder->transaction_id)->first();

        if ($ecourierOrder) {
            $business_id = request()->session()->get('user.business_id');
            $couriers = Courier::where('business_id', $business_id)->get()->toArray();
            foreach ($couriers as $courier) {
                if ($courier['name'] === 'Ecourier') {
                    $ecourierId = $courier['id'];
                    $body = [
                        "tracking" => $ecourierOrder->tracking_id,
                        "comment" => "Cancel this order"
                    ];
                    $data = json_encode($body);
                    $curl = curl_init();
                    curl_setopt_array($curl, [
                        CURLOPT_URL => 'https://backoffice.ecourier.com.bd/api/cancel-order',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => '',
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 0,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => 'POST',
                        CURLOPT_POSTFIELDS => $data,
                        CURLOPT_HTTPHEADER => [
                            'USER-ID: ' . $courier['client_id'],
                            'API-KEY: ' . $courier['client_secret'],
                            'API-SECRET: ' . $courier['client_password'],
                            'Content-Type: application/json',
                        ],
                    ]);

                    $response = curl_exec($curl);
                    curl_close($curl);
                    $responseData = json_decode($response);
                    if ($responseData && $responseData->success) {
                        if ($ecourierOrder->delete()) {
                            $mainorder->shipping_status = "packed";
                            $mainorder->save();
                            return 1;
                        }
                    }
                    return $response; // No need to continue once found
                }
            }

        }
        return 0;
    }

    private function courierlog($invoiceNo, $consignmentId, $storeID, $orderStatus, $errors, $createdBy, $business_id, $courier_name)
    {
        try {
            $courierlog = new Courier_logs;
            $courierlog->invoice_no = $invoiceNo;
            $courierlog->courier_id = $consignmentId;
            $courierlog->store_no = $storeID;
            $courierlog->status = $orderStatus;
            $courierlog->reason = $errors;
            $courierlog->created_by = $createdBy;
            $courierlog->created_at = now();
            $courierlog->business_id = $business_id;
            $courierlog->courier_name = $courier_name;
            $courierlog->save();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
        }
    }

    public function courierlogindex()
    {
        return view('courier.logs');
    }

    public function handleUpload(Request $request)
    {
        $request->validate([
            'uploaded_file' => 'required|mimes:csv,txt'
        ]);

        $uploadedFile = $request->file('uploaded_file');
        $business_id = request()->session()->get('user.business_id');

        // Generate a new unique filename based on the original file name and the default zone
        $newFileName = time() . '.' . $uploadedFile->getClientOriginalExtension();
        $existingRecords = CourierZoneMappings::where('business_id', $business_id)->exists();

        // If there are existing records, delete them
        if ($existingRecords) {
            CourierZoneMappings::where('business_id', $business_id)->delete();
        }
        // Save the file to the 'files' folder with the new name
        $uploadedFile->storeAs('public/files', $newFileName);

        // Process the CSV file and save to the database
        $csvFile = Storage::path('public/files/' . $newFileName);

        $csvData = array_map('str_getcsv', file($csvFile));
        $headers = array_shift($csvData);

        foreach ($csvData as $row) {
            $data = array_combine($headers, $row);
            // Assuming $data is properly formatted and corresponds to the columns in your model
            $mapping = [
                'pathao_city_id' => $data['Pathao City Id'],
                'pathao_zone_id' => $data['zone_id'],
                'city_name' => $data['City Name'],
                'merged_zones_name' => $data['Merged Zone'],
                'ecourier_area_name' => $data['Area Name'],
                'ecourier_postal_code' => $data['post_code'],
                'ecourier_area_id' => $data['area_id'],
                'ecourier_hub_id' => $data['hub_id'],
                'default_zone' => $data['Default Zone'],
                'division_id' => $data['division_id'],
                'business_id' => $business_id
                // ... map other columns as needed
            ];
            CourierZoneMappings::create($mapping);
        }

        // Your logic to process the uploaded file or save information about it goes here

        return redirect()->back()->with('success', 'File uploaded successfully');
    }

}
