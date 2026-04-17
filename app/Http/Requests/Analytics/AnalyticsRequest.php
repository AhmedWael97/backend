<?php

namespace App\Http\Requests\Analytics;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class AnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start' => ['nullable', 'date', 'before_or_equal:end'],
            'end' => ['nullable', 'date'],
            'granularity' => ['nullable', 'in:hour,day,week,month'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function start(): Carbon
    {
        return $this->filled('start')
            ? Carbon::parse($this->input('start'))->startOfDay()
            : now()->subDays(7)->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->filled('end')
            ? Carbon::parse($this->input('end'))->endOfDay()
            : now()->endOfDay();
    }

    public function granularity(): string
    {
        return $this->input('granularity', 'day');
    }

    public function limit(): int
    {
        return (int) $this->input('limit', 10);
    }
}
