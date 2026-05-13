<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
  'ok' => false,
  'status' => 'work_in_progress',
  'error' => 'coupon_activation_not_available_yet',
  'message' => 'Coupon activation is currently work in progress.',
  'todo' => 'Connect this endpoint to promo_codes.json / coupon workflow.'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
