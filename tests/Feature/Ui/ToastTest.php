<?php

test('toast listens to notify defaulting the type to success and hides after six seconds', function () {
    $this->blade('<x-ui.toast />')
        ->assertSee('role="status"', false)
        ->assertSee("x-on:notify.window=\"toast = { type: 'success', ...\$event.detail }", false)
        ->assertSee('setTimeout(() => toast = null, 6000)', false)
        ->assertSee("toast?.type === 'error'", false)
        ->assertSee('aria-label="Fechar"', false);
});

test('toast opens the receipt PDF in a new tab instead of the prototype simulation', function () {
    $this->blade('<x-ui.toast />')
        ->assertSee('x-if="toast?.receiptUrl"', false)
        ->assertSee('x-bind:href="toast.receiptUrl"', false)
        ->assertSee('target="_blank"', false)
        ->assertSee('Imprimir recibo')
        ->assertDontSee('Abrindo recibo em PDF');
});
