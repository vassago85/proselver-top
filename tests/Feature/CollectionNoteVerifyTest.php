<?php

test('a collection note link that is not a uuid is a 404', function () {
    $this->get('/verify/YjM1MTY3ZD')->assertNotFound();
});
