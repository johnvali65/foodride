<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
   protected $fillable = ['android_latest_version_no', 'android_latest_version_note', 'android_download_link','android_force_update_required','ios_latest_version_no','ios_latest_version_note','ios_download_link','ios_force_update_required'];

    public function listData() {
        return AppSetting::orderBy('created_at', 'DESC')->get();
    }

    public function storeData($data) {
        return AppSetting::create($data);
    }

    public function getDataById($id) {
        return AppSetting::find($id);
    }

    public function updateData($data, $id) {
        return AppSetting::find($id)->update($data);
    }

    public function deleteData($id) {
        return AppSetting::find($id)->delete();
    }
}
