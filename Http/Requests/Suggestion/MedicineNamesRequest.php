<?php

namespace App\Http\Requests\Suggestion;

use App\Http\Requests\Request;

class MedicineNamesRequest extends Request
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'q' => 'required|string|min:3',
            'limit' => 'integer|min:5|max:25',
        ];
    }
}
