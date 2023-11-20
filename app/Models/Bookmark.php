<?php

namespace App\Models;

// 书签表, nexusModel是通用的父类
class Bookmark extends NexusModel
{
//    表名
    protected $table = 'bookmarks';
//    构造函数 create()或fill()方法时使用
    protected $fillable = ['userid', 'torrentid'];

//当你通过 Bookmark 模型获取数据时，可以通过 $bookmark->torrent 来访问与该书签相关联的种子（torrent）信息。

// 我的某个字段属于他的id (他只有一个)
    public function torrent()
    {
        return $this->belongsTo(Torrent::class, 'torrentid');
    }
//*************************************************************************************
//*************************************************************************************
//*************************************************************************************
// 我的id对应他们的这个字段 (他们有很多)
//    public function bookmarks(): \Illuminate\Database\Eloquent\Relations\HasMany
//    {
//        return $this->hasMany(Bookmark::class, 'torrentid');
//    }
//*************************************************************************************
//*************************************************************************************
//*************************************************************************************



//当你通过 Bookmark 模型获取数据时，可以通过 $bookmark->user 来访问与该书签相关联的用户信息。
    public function user()
    {
        return $this->belongsTo(Torrent::class, 'userid');
    }
}
