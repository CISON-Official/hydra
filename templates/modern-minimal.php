<?php
/**
 * Modern Minimalist Certificate
 * Uses left border, clean sans-serif, data grid
 */
?>
<div style="max-width: 900px; margin: 0 auto; background: white; box-shadow: 0 2px 15px rgba(0,0,0,0.05); font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <div style="border-left: 8px solid #2c3e50; padding: 40px 50px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <h1 style="font-size: 28px; font-weight: 300; color: #2c3e50; margin: 0 0 5px;">Certificate of Completion</h1>
                <div style="height: 2px; width: 60px; background: #e67e22; margin: 15px 0 25px;"></div>
            </div>
            <div style="text-align: right;">
                <span style="background: #ecf0f1; padding: 5px 12px; font-size: 12px;">ID: <?php echo substr(esc_attr($cert_key), 0, 8); ?></span>
            </div>
        </div>
        
        <p style="font-size: 18px; color: #7f8c8d; margin-bottom: 15px;">This certificate is awarded to</p>
        <h2 style="font-size: 42px; font-weight: 500; margin: 0 0 20px; color: #2c3e50;"><?php echo esc_html($holder_name); ?></h2>
        
        <div style="background: #f9f9f9; padding: 20px; margin: 30px 0; border-radius: 4px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div><strong>Award:</strong> <?php echo esc_html($cert_title); ?></div>
                <div><strong>Issued:</strong> <?php echo esc_html($date_issued); ?></div>
                <div><strong>Expiry:</strong> <?php echo esc_html($date_expiry); ?></div>
                <div><strong>Main certificate:</strong> <?php echo $is_main ? 'Yes' : 'No'; ?></div>
            </div>
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <div>
                <?php if ($qr_image_url): ?>
                    <img src="<?php echo esc_url($qr_image_url); ?>" width="100" height="100" alt="QR">
                <?php endif; ?>
            </div>
            <div style="font-size: 12px; color: #95a5a6;">
                Verify online: <a href="<?php echo esc_url($verification_url); ?>" style="color:#e67e22;">scan QR or click here</a>
            </div>
        </div>
    </div>
</div>