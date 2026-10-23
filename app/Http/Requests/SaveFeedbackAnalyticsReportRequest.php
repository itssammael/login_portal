<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveFeedbackAnalyticsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAnalytics', $this->route('feedback'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'configuration' => ['required', 'array:title,description,footer,columns,show_submission_count,refresh_seconds,period_start,period_end,charts,sections'],
            'configuration.title' => ['required', 'string', 'max:200'],
            'configuration.description' => ['nullable', 'string', 'max:2000'],
            'configuration.footer' => ['nullable', 'string', 'max:1000'],
            'configuration.columns' => ['required', 'integer', 'between:1,12'],
            'configuration.show_submission_count' => ['required', 'boolean'],
            'configuration.refresh_seconds' => ['required', 'integer', 'between:300,86400'],
            'configuration.period_start' => ['nullable', 'date_format:Y-m-d'],
            'configuration.period_end' => ['nullable', 'date_format:Y-m-d'],
            'configuration.charts' => ['present', 'array', 'max:24'],
            'configuration.charts.*' => ['array:id,title,question,type,aggregation,color,legend,labels,annotation,width,height,position'],
            'configuration.charts.*.id' => ['required', 'string', 'alpha_dash', 'max:80', 'distinct'],
            'configuration.charts.*.title' => ['required', 'string', 'max:200'],
            'configuration.charts.*.question' => ['required', 'string', 'max:100'],
            'configuration.charts.*.type' => ['required', 'in:bar,column,line,area,pie,donut,radar,table,kpi'],
            'configuration.charts.*.aggregation' => ['required', 'in:count,percentage,mean,sum,median,min,max,stddev'],
            'configuration.charts.*.color' => ['required', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'],
            'configuration.charts.*.legend' => ['required', 'boolean'],
            'configuration.charts.*.labels' => ['required', 'boolean'],
            'configuration.charts.*.annotation' => ['nullable', 'string', 'max:1000'],
            'configuration.charts.*.position' => ['sometimes', 'integer', 'between:0,35'],
            'configuration.charts.*.width' => ['required', 'integer', 'between:1,12'],
            'configuration.charts.*.height' => ['sometimes', 'nullable', 'integer', 'between:1,9'],
            'configuration.sections' => ['present', 'array', 'max:12'],
            'configuration.sections.*' => ['array:id,title,body,width,height,position'],
            'configuration.sections.*.id' => ['sometimes', 'string', 'alpha_dash', 'max:80', 'distinct'],
            'configuration.sections.*.position' => ['sometimes', 'integer', 'between:0,35'],
            'configuration.sections.*.width' => ['sometimes', 'integer', 'between:1,12'],
            'configuration.sections.*.height' => ['sometimes', 'nullable', 'integer', 'between:1,9'],
            'configuration.sections.*.title' => ['required', 'string', 'max:200'],
            'configuration.sections.*.body' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
