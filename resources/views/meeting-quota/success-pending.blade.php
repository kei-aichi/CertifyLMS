@extends('layouts.app')

@section('title', '決済確認中')

@section('content')
    <div class="max-w-xl mx-auto mt-8">
        <x-card padding="lg" shadow="md" class="text-center">
            <h1 class="text-xl font-bold text-ink-900">決済結果を確認しています</h1>
            <p class="text-sm text-ink-600 mt-2">
                {{ $message ?? '反映状況が確定するまでしばらくお待ちください。' }}
            </p>
            <div class="mt-6">
                <x-link-button href="{{ route('dashboard.index') }}" variant="primary">
                    ダッシュボードへ戻る
                </x-link-button>
            </div>
        </x-card>
    </div>
@endsection
