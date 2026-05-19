<?php
/**
 * Classic Institutional Certificate
 * Variables: $holder_name, $cert_title, $date_issued, $date_expiry, $qr_image_url, $verification_url
 */
?>
<div style="width: 800px; margin: 0 auto; border: 15px solid #d4af37; padding: 30px; background: #fff8f0; text-align: center; font-family: 'Times New Roman', serif;">
    <h1 style="font-size: 48px; letter-spacing: 4px; margin-bottom: 20px;">CERTIFICATE OF ACHIEVEMENT</h1>
    <div style="border-top: 2px solid #d4af37; width: 100px; margin: 20px auto;"></div>
    <p style="font-size: 18px;">This certificate is proudly presented to</p>
    <h2 style="font-size: 36px; margin: 20px 0; font-weight: normal;"><?php echo esc_html($holder_name); ?></h2>
    <p style="font-size: 18px;">for successfully completing the requirements of</p>
    <h3 style="font-size: 28px; margin: 15px 0;"><?php echo esc_html($cert_title); ?></h3>
    <p style="font-size: 16px;">Issued on: <strong><?php echo esc_html($date_issued); ?></strong></p>
    <p style="font-size: 16px;">Expires: <strong><?php echo esc_html($date_expiry); ?></strong></p>
    <div style="margin-top: 40px;">
        <?php if ($qr_image_url): ?>
            <img src="<?php echo esc_url($qr_image_url); ?>" width="120" height="120" alt="Verification QR Code">
            <p style="font-size: 12px;">Scan to verify authenticity</p>
        <?php endif; ?>
    </div>
    <div style="margin-top: 50px; font-size: 12px; border-top: 1px solid #ccc; padding-top: 15px;">
        Verify at: <?php echo esc_url($verification_url); ?>
    </div>
</div>