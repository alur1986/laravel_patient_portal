<?php

namespace App\Http\Controllers;

class ChatController extends Controller
{
    public function index()
    {
//        $account = Account::query()->where('id', $request->user()->patientAccount->account_id)->first();
//        config(['database.connections.juvly_practice.database' => $account->database_name]);
//        $rooms = Room::query()
//            ->whereHas('room_users', function($query) {
//                return $query->where('user_id', '=', Auth::user()->id);
//            })
//            ->get();

        return view('app.chat.index');
    }
}
