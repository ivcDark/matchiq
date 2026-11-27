<?php

namespace App\Http\Controllers;

use App\Http\Responses\Clubs\ClubShowResponse;
use App\Services\Clubs\BuildClubShow;

class ClubController extends Controller
{
    /** Разделы (Сводка, Календарь, Результаты) переключаются на клиенте по хешу URL. */
    public function show(BuildClubShow $show, int $id): ClubShowResponse
    {
        return $show->handle($id) ?? abort(404);
    }
}
