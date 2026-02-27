<?php

test('homepage redirects to login', function () {
    $this->get('/')->assertRedirect('/login');
});
