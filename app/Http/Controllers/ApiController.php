<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\MetricsService;

class ApiController extends Controller
{
    public function normal()
    {
        return response()->json([
            'status' => 'ok',
            'message' => 'Normal response',
        ]);
    }

    public function slow(Request $request)
    {
        if ($request->query('hard') == '1') {
            $sleep = rand(5, 7);
            sleep($sleep);
        } else {
            $sleep = rand(1, 3);
            sleep($sleep);
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'Slow response',
            'sleep_seconds' => $sleep,
        ]);
    }

    public function error()
    {
        abort(500, 'Simulated server error');
    }

    public function random()
    {
        $rand = rand(1, 3);

        if ($rand === 1) {
            return response()->json(['status' => 'ok', 'message' => 'Random ok']);
        } elseif ($rand === 2) {
            sleep(rand(1, 2));
            return response()->json(['status' => 'slow', 'message' => 'Random slow']);
        } else {
            abort(500, 'Random error');
        }
    }

    public function db(Request $request)
    {
        if ($request->query('fail') == '1') {
            DB::table('nonexistent_table')->get();
        }

        $users = DB::table('users')->get();

        return response()->json([
            'status' => 'ok',
            'count' => $users->count(),
        ]);
    }

    public function validate(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'age'   => 'required|integer|between:18,60',
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => 'Validation passed',
        ]);
    }

    public function metrics()
    {
        $output = MetricsService::renderMetrics();
        return response($output, 200)
            ->header('Content-Type', 'text/plain; version=0.0.4');
    }
}