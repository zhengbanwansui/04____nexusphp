<?php

namespace App\Models;

/*
 * 新增的自定义mysql表
 * 记录用户的贷款情况
 **/
class CustomLoanRepayment extends NexusModel
{
    protected $table = 'custom_loan_repayment';

    protected $fillable = ['user_id', 'seedbonus', 'comment'];

    public $timestamps = true;

}
