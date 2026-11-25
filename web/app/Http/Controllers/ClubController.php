<?php

namespace App\Http\Controllers;

use App\Support\MockClub;
use Inertia\Inertia;
use Inertia\Response;

class ClubController extends Controller
{
    /**
     * Страница клуба: сводка (место в РПЛ, Кубок России, статистика, игроки, голы). Моки без реальной статистики.
     * Разделы (Сводка, Календарь, Результаты, Статистика) переключаются на клиенте по хешу URL.
     */
    public function show(int $id): Response
    {
        $data = MockClub::find($id);

        abort_if($data === null, 404);

        return Inertia::render('Clubs/Show', $data);
    }
}
