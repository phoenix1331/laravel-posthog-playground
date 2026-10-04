<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Basket\CurrentBasket;
use App\Models\Basket;
use App\Models\TravelOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AddTravelRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'travel_option_id' => [
                'required',
                'integer',
                Rule::exists('travel_options', 'id')->where('fixture_id', $this->basket()->fixture_id),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->travelOption()->remaining() < $this->basket()->quantity) {
                    $validator->errors()->add('travel_option_id', 'Not enough seats left on that coach.');
                }
            },
        ];
    }

    /**
     * Route middleware guarantees a basket exists.
     */
    public function basket(): Basket
    {
        return app(CurrentBasket::class)->get();
    }

    public function travelOption(): TravelOption
    {
        return TravelOption::findOrFail($this->integer('travel_option_id'));
    }
}
