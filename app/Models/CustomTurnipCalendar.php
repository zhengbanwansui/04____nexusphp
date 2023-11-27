<?php

namespace App\Models;

/*
 * 新增的自定义mysql表
 * 记录大头菜日线
 **/
class CustomTurnipCalendar extends NexusModel
{
    protected $table = 'custom_turnip_calendar';

    protected $fillable = ['date', 'price', 'name'];

    public $timestamps = false;

}
