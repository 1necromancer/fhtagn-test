<?php

namespace App\Services\Orders;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\PickupPoint;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlaceOrderService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function place(array $payload): Order
    {
        $data = $this->validate($payload);

        return DB::transaction(function () use ($data) {
            $lines = $this->resolveLines($data['items']);

            $subtotal = '0';
            foreach ($lines as $line) {
                $subtotal = bcadd($subtotal, $line['line_total'], 2);
            }

            $order = Order::query()->create([
                'user_id' => $data['user_id'],
                'contact_email' => $data['contact_email'],
                'contact_phone' => $data['contact_phone'],
                'status' => OrderStatus::PendingPayment,
                'currency' => $data['currency'],
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'placed_at' => Carbon::now(),
            ]);

            foreach ($lines as $line) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'product_name' => $line['product_name'],
                    'sku' => $line['sku'],
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                ]);
            }

            $this->createDelivery($order, $data);
            $this->createPayment($order, $data);

            return $order->load(['items', 'delivery.pickupPoint', 'payment']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validate(array $payload): array
    {
        $validator = Validator::make($payload, [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:32'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'delivery.type' => ['required', Rule::enum(DeliveryType::class)],
            'delivery.pickup_point_id' => [
                'prohibited_if:delivery.type,'.DeliveryType::Address->value,
                Rule::requiredIf(fn () => ($payload['delivery']['type'] ?? null) === DeliveryType::Pickup->value),
                'nullable',
                'integer',
                'exists:pickup_points,id',
            ],
            'delivery.city' => [
                'prohibited_if:delivery.type,'.DeliveryType::Pickup->value,
                Rule::requiredIf(fn () => ($payload['delivery']['type'] ?? null) === DeliveryType::Address->value),
                'nullable',
                'string',
                'max:120',
            ],
            'delivery.street' => [
                'prohibited_if:delivery.type,'.DeliveryType::Pickup->value,
                Rule::requiredIf(fn () => ($payload['delivery']['type'] ?? null) === DeliveryType::Address->value),
                'nullable',
                'string',
                'max:120',
            ],
            'delivery.house' => [
                'prohibited_if:delivery.type,'.DeliveryType::Pickup->value,
                Rule::requiredIf(fn () => ($payload['delivery']['type'] ?? null) === DeliveryType::Address->value),
                'nullable',
                'string',
                'max:32',
            ],
            'delivery.apartment' => [
                'prohibited_if:delivery.type,'.DeliveryType::Pickup->value,
                'nullable',
                'string',
                'max:32',
            ],
            'payment.type' => ['required', Rule::enum(PaymentType::class)],
            'payment.credit_provider' => [
                'prohibited_if:payment.type,'.PaymentType::Card->value,
                Rule::requiredIf(fn () => ($payload['payment']['type'] ?? null) === PaymentType::Credit->value),
                'nullable',
                'string',
                'max:120',
            ],
            'payment.credit_term_months' => [
                'prohibited_if:payment.type,'.PaymentType::Card->value,
                Rule::requiredIf(fn () => ($payload['payment']['type'] ?? null) === PaymentType::Credit->value),
                'nullable',
                'integer',
                'min:1',
                'max:360',
            ],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();
        $data['currency'] = strtoupper((string) ($data['currency'] ?? 'RUB'));

        return $data;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @return list<array{product_id: int, product_name: string, sku: string|null, unit_price: string, quantity: int, line_total: string}>
     */
    private function resolveLines(array $items): array
    {
        $productIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['product_id'],
            $items,
        )));

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($productIds)) {
            throw ValidationException::withMessages([
                'items' => ['Один или несколько товаров недоступны.'],
            ]);
        }

        $lines = [];
        foreach ($items as $row) {
            /** @var Product $product */
            $product = $products->get($row['product_id']);
            $qty = (int) $row['quantity'];
            $unit = (string) $product->price;
            $lineTotal = bcmul($unit, (string) $qty, 2);

            $lines[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'unit_price' => $unit,
                'quantity' => $qty,
                'line_total' => $lineTotal,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createDelivery(Order $order, array $data): OrderDelivery
    {
        $delivery = $data['delivery'];
        $type = DeliveryType::from($delivery['type']);

        if ($type === DeliveryType::Pickup) {
            PickupPoint::query()->whereKey($delivery['pickup_point_id'])->firstOrFail();
        }

        return OrderDelivery::query()->create([
            'order_id' => $order->id,
            'type' => $type,
            'pickup_point_id' => $type === DeliveryType::Pickup ? $delivery['pickup_point_id'] : null,
            'city' => $type === DeliveryType::Address ? $delivery['city'] : null,
            'street' => $type === DeliveryType::Address ? $delivery['street'] : null,
            'house' => $type === DeliveryType::Address ? $delivery['house'] : null,
            'apartment' => $type === DeliveryType::Address ? ($delivery['apartment'] ?? null) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createPayment(Order $order, array $data): OrderPayment
    {
        $payment = $data['payment'];
        $type = PaymentType::from($payment['type']);

        return OrderPayment::query()->create([
            'order_id' => $order->id,
            'type' => $type,
            'credit_provider' => $type === PaymentType::Credit ? $payment['credit_provider'] : null,
            'credit_term_months' => $type === PaymentType::Credit ? $payment['credit_term_months'] : null,
        ]);
    }
}
