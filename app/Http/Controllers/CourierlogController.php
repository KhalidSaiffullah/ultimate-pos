<?php

namespace App\Http\Controllers;

use App\Courier;
use App\Store;
use Carbon\Carbon;
use App\Courier_logs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

use App\Transaction;

class CourierlogController extends Controller
{
    public function index(Request $request)
    {
        $currentDate = Carbon::now();
        $logs = Courier_logs::all();
        $storeNames = Store::pluck('name', 'name');
        $statusOptions = ['Success', 'Failed'];
        $courierOptions = Courier::pluck('name', 'name');
        $courierOptions->put('Manual', 'Manual');


        $today = $currentDate->toDateString();
        $successCount = Courier_logs::where('status', 'Success')->whereDate('created_at', $today)->count();
        $failureCount = Courier_logs::where('status', 'Failed')->whereDate('created_at', $today)->count();

        return view('courier.logs', compact('currentDate', 'successCount', 'failureCount', 'storeNames', 'statusOptions', 'courierOptions'));
    }

    public function getLogsData(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $data = Courier_logs::selectRaw('courier_logs.*, COALESCE(stores.name, "default") as store_name,transactions.shipping_status AS shipping_status')
                ->leftJoin('stores', 'courier_logs.store_no', '=', 'stores.store_id')
                ->leftJoin('transactions', 'courier_logs.invoice_no', '=', 'transactions.invoice_no')
                ->distinct()
                ->where('courier_logs.business_id', $business_id)
                ->when($request->filled('statusFilter'), function ($query) use ($request) {
                    return $query->where('status', $request->statusFilter);
                })
                ->when($request->filled('storeNoFilter'), function ($query) use ($request) {
                    // return $query->where('stores.name', $request->storeNoFilter);
                    if ($request->storeNoFilter === 'default') {
                        return $query->where('store_no', 0);

                    } else {
                        return $query->where('stores.name', $request->storeNoFilter);
                    }
                })
                ->when($request->filled('from_date') && $request->filled('to_date'), function ($query) use ($request) {
                    $fromDate = Carbon::parse($request->from_date)->startOfDay();
                    $toDate = Carbon::parse($request->to_date)->endOfDay();
                    return $query->whereBetween('courier_logs.created_at', [$fromDate, $toDate]);
                });

            return Datatables::of($data)->make(true);
        }

        return abort(403, 'Unauthorized');
    }

    public function deleteLog(Request $request, $id)
    {
        if ($request->ajax()) {
            $log = Courier_logs::find($id);
            if ($log) {
                $invoiceNum = $log->invoice_no;
                $this->updateOrderStatusIntoTransaction($invoiceNum);
                $log->delete();                
                return response()->json(['success' => true, 'message' => 'Log deleted successfully']);
            } else {
                return response()->json(['success' => false, 'message' => 'Log not found']);
            }
        }

        return response()->json(['success' => false, 'message' => 'Unauthorized']);
    }

    function bulkDelete(Request $request)
    {
        $ids = $request->input('id');
        $Courier_logs = Courier_logs::whereIn('id', $ids);
        $invoiceNums = $Courier_logs->pluck('invoice_no'); 

        if ($Courier_logs->delete()) {
            foreach ($invoiceNums as $invoiceNum) {
                $this->updateOrderStatusIntoTransaction($invoiceNum);
            }

            return response()->json(['success' => true, 'message' => 'Selected logs deleted successfully']);
        } else {
            return response()->json(['success' => false, 'message' => 'No logs selected for deletion']);


        }
    }


    private function updateOrderStatusIntoTransaction($invoiceNum)
    {
        Transaction::where('invoice_no', $invoiceNum)
            ->update(['shipping_status' => 'packed']);
    }
}
