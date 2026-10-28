<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchStockTransfer extends Model
{
    protected $fillable = ['transfer_code', 'request_key', 'request_hash', 'from_branch_id', 'to_branch_id', 'created_by', 'items', 'total_value', 'notes'];

    protected $casts = ['items' => 'array', 'total_value' => 'decimal:2'];

    protected $hidden = ['request_key', 'request_hash'];

    public function fromBranch() { return $this->belongsTo(Branch::class, 'from_branch_id'); }
    public function toBranch() { return $this->belongsTo(Branch::class, 'to_branch_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
