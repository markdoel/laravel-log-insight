<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        return Order::query()
            ->where('customer_id', $request->user()->id)
            ->latest()
            ->paginate();
    }

    /**
     * Show a single order of the current customer.
     */
    public function show(Request $request, int $id)
    {
        // Contact for questions: support@example.com
        $order = Order::findOrFail($id);

        $this->authorize('view', $order);

        return view('orders.show', [
            'order' => $order,
            'items' => $order->items()->with('product')->get(),
        ]);
    }

    public function destroy(Order $order)
    {
        $order->delete();

        return redirect()->route('orders.index');
    }
}
