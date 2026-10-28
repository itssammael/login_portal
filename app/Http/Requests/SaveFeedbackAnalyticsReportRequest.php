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
            'configuration.title' => ['required', 'string', 'max:500'],
            'configuration.description' => ['nullable', 'string', 'max:2000'],
            'configuration.footer' => ['nullable', 'string', 'max:1000'],
            'configuration.columns' => ['required', 'integer', 'between:1,12'],
            'configuration.show_submission_count' => ['required', 'boolean'],
            'configuration.refresh_seconds' => ['required', 'integer', 'between:300,86400'],
            'configuration.period_start' => ['nullable', 'date_format:Y-m-d'],
            'configuration.period_end' => ['nullable', 'date_format:Y-m-d'],
            'configuration.charts' => ['present', 'array', 'max:24'],
            'configuration.charts.*' => ['array:id,kind,title,question,type,aggregation,color,legend,labels,annotation,width,height,position,parent_id,rows'],
            'configuration.charts.*.id' => ['required', 'string', 'alpha_dash', 'max:80', 'distinct'],
            'configuration.charts.*.kind' => ['sometimes', 'nullable', 'in:standard,custom'],
            'configuration.charts.*.title' => ['required', 'string', 'max:500'],
            'configuration.charts.*.question' => ['nullable', 'string', 'max:100'],
            'configuration.charts.*.type' => ['required', 'in:bar,column,line,area,pie,donut,radar,table,kpi'],
            'configuration.charts.*.aggregation' => ['required', 'in:count,percentage,mean,sum,median,min,max,stddev'],
            'configuration.charts.*.color' => ['required', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'],
            'configuration.charts.*.legend' => ['required', 'boolean'],
            'configuration.charts.*.labels' => ['required', 'boolean'],
            'configuration.charts.*.annotation' => ['nullable', 'string', 'max:1000'],
            'configuration.charts.*.position' => ['sometimes', 'integer', 'between:0,60'],
            'configuration.charts.*.width' => ['required', 'integer', 'between:1,12'],
            'configuration.charts.*.height' => ['sometimes', 'nullable', 'integer', 'between:1,9'],
            'configuration.charts.*.parent_id' => ['sometimes', 'nullable', 'string', 'alpha_dash', 'max:80'],
            'configuration.charts.*.rows' => ['sometimes', 'nullable', 'array', 'max:24'],
            'configuration.charts.*.rows.*' => ['array:id,label,source,question,operation,value'],
            'configuration.charts.*.rows.*.id' => ['sometimes', 'nullable', 'string', 'alpha_dash', 'max:80'],
            'configuration.charts.*.rows.*.label' => ['required_with:configuration.charts.*.rows', 'string', 'max:500'],
            'configuration.charts.*.rows.*.source' => ['required_with:configuration.charts.*.rows', 'in:question,manual'],
            'configuration.charts.*.rows.*.question' => ['nullable', 'string', 'max:100'],
            'configuration.charts.*.rows.*.operation' => ['nullable', 'in:sum,average,mean,count'],
            'configuration.charts.*.rows.*.value' => ['nullable', 'numeric'],
            'configuration.sections' => ['present', 'array', 'max:24'],
            'configuration.sections.*' => ['array:id,kind,title,show_heading,body,width,height,rows,columns,border_width,border_radius,border_style,border_color,parent_id,position'],
            'configuration.sections.*.id' => ['sometimes', 'string', 'alpha_dash', 'max:80', 'distinct'],
            'configuration.sections.*.kind' => ['sometimes', 'nullable', 'in:text,blank'],
            'configuration.sections.*.show_heading' => ['sometimes', 'boolean'],
            'configuration.sections.*.position' => ['sometimes', 'integer', 'between:0,60'],
            'configuration.sections.*.width' => ['sometimes', 'integer', 'between:1,12'],
            'configuration.sections.*.height' => ['sometimes', 'nullable', 'integer', 'between:1,9'],
            'configuration.sections.*.rows' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
            'configuration.sections.*.columns' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
            'configuration.sections.*.border_width' => ['sometimes', 'nullable', 'integer', 'between:0,16'],
            'configuration.sections.*.border_radius' => ['sometimes', 'nullable', 'integer', 'between:0,48'],
            'configuration.sections.*.border_style' => ['sometimes', 'nullable', 'in:none,solid,dashed,dotted,double'],
            'configuration.sections.*.border_color' => ['sometimes', 'nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'],
            'configuration.sections.*.parent_id' => ['sometimes', 'nullable', 'string', 'alpha_dash', 'max:80'],
            'configuration.sections.*.title' => ['nullable', 'string', 'max:500'],
            'configuration.sections.*.body' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
