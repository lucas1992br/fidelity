<?php

test('the home page requires authentication', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
