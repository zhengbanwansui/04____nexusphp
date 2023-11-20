<?php

namespace App\Models;

use Nexus\Database\NexusDB;

class TorrentBuyLog extends NexusModel
{
    // 类似lombok自动生成的创建时间和更新时间(create_at update_at)
    public $timestamps = true;

    // 构造函数
    protected $fillable = ['uid', 'torrent_id', 'price', 'channel'];

    // 外键关联uid到user表
    public function user()
    {
        return $this->belongsTo(User::class, 'uid');
    }

    // 外键关联torrent_id到Torrent表
    public function torrent()
    {
        return $this->belongsTo(Torrent::class, 'torrent_id');
    }

}
