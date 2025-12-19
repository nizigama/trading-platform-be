<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'symbol_id' => 'required|integer|exists:symbols,id',
            'side' => 'required|integer|in:' . Order::SIDE_BUY . ',' . Order::SIDE_SELL,
            'price' => 'required|numeric|gt:0',
            'amount' => 'required|numeric|gt:0',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'symbol_id.exists' => 'The selected symbol does not exist.',
            'side.in' => 'Side must be 1 (buy) or 2 (sell).',
        ];
    }
}
