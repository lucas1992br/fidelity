<?php

use Illuminate\Support\Facades\Validator;

test('the default locale is pt_BR', function () {
    expect(app()->getLocale())->toBe('pt_BR')
        ->and(config('app.fallback_locale'))->toBe('pt_BR');
});

test('the required validation message is translated to pt-BR', function () {
    expect(__('validation.required', ['attribute' => 'nome']))
        ->toBe('O campo nome é obrigatório.');

    $validator = Validator::make([], ['nome' => 'required']);

    expect($validator->errors()->first('nome'))->toBe('O campo nome é obrigatório.');
});

test('the application timezone is America/Sao_Paulo', function () {
    expect(config('app.timezone'))->toBe('America/Sao_Paulo')
        ->and(now()->timezoneName)->toBe('America/Sao_Paulo');
});
