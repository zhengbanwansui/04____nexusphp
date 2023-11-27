<?php

namespace App\Models;

/*
 * 新增的自定义mysql表
 * 记录大头菜日线
 **/
class CustomTurnip extends NexusModel
{
    protected $table = 'custom_turnip';

    protected $fillable = ['username', 'user_id', 'comment', 'number', 'price', 'seedbonus'];

    public $timestamps = true;

}
