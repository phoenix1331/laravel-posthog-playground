<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Basket;
use App\Models\Fixture;
use App\Models\TicketAllocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SelectSeatsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ticket_allocation_id' => [
                'required',
                'integer',
                Rule::exists('ticket_allocations', 'id')->where('fixture_id', $this->fixture()->id),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Basket::MAX_TICKETS],
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

                if ($this->fixture()->kicks_off_at->isPast()) {
                    $validator->errors()->add('ticket_allocation_id', 'This fixture has already kicked off.');

                    return;
                }

                if ($this->allocation()->remaining() < $this->integer('quantity')) {
                    $validator->errors()->add('quantity', 'Not enough tickets left in that stand.');
                }
            },
        ];
    }

    public function fixture(): Fixture
    {
        return $this->route('fixture');
    }

    public function allocation(): TicketAllocation
    {
        return TicketAllocation::findOrFail($this->integer('ticket_allocation_id'));
    }
}
