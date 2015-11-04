<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Login extends Model
{
    protected $table = 'login';
    public $timestamps = false;


    //保存用户
    public function scopeInSave($query, $data)
    {
        $tp = $query->where('wxuin', $data['wxuin'])->first();
        if ($tp) {
            $query->where('wxuin', $data['wxuin'])->update($data);
        } else {
            $query->insert($data);
        }
    }


}
