<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Order;
class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Get the data from the request
        $data = $request->all();
        // Save the data to a JSON file
        $filename = 'webhook_data_' . time() . '.json';
        Storage::put('public/webhook_data/' . $filename, json_encode($data));
        // Save the filename and location to the database
        $order = new Order;
        $order->filename = $filename;
        $order->location = 'public/webhook_data/' . $filename;
        $order->save();
        // Return a response to the webhook sender
        return response()->json(['success' => true]);
    }
    public function ProductcreatedController(Request $request)
    {
        // Get the data from the request
        $data = $request->all();
        // Save the data to a JSON file
        $filename = 'Product_created_' . time() . '.json';
        Storage::put('public/webhook_data/' . $filename, json_encode($data));
        // Save the filename and location to the database
        $order = new Order;
        $order->filename = $filename;
        $order->location = 'public/webhook_data/' . $filename;
        $order->save();
        // Return a response to the webhook sender
        return response()->json(['success' => true]);
    }
}