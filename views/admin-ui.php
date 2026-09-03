<?php
    $plugin_slug = 'bangla-qr';
    $plugin_info = pp_get_plugin_info($plugin_slug);
    $settings = pp_get_plugin_setting($plugin_slug);
    
    $plugin_dir_name = !empty($plugin_info['plugin_dir']) ? $plugin_info['plugin_dir'] : 'payment-gateway';
    $plugin_base_url = pp_get_site_url() . '/pp-content/plugins/' . $plugin_dir_name . '/' . $plugin_slug;
?>

<form id="smtpSettingsForm" method="post" action="" onsubmit="return handleAdminSettingsSubmit(event, this);">
    <!-- Page Header -->
    <div class="page-header">
      <div class="row align-items-end">
        <div class="col-sm mb-2 mb-sm-0">
          <h1 class="page-header-title">Edit Gateway - Bangla QR</h1>
          <p class="text-muted mb-0">100% Automated Interoperable Bangla QR Payment Gateway.</p>
        </div>
      </div>
    </div>

    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="d-grid gap-3 gap-lg-5">
          <!-- Gateway Information Card -->
          <div class="card">
            <div class="card-header">
              <h2 class="card-title h4">Gateway Information</h2>
            </div>

            <!-- Body -->
            <div class="card-body">
                <input type="hidden" name="action" value="plugin_update-submit">
                <input type="hidden" name="plugin_slug" value="<?php echo $plugin_slug?>">
                
                <div class="row mb-4">
                  <div class="col-sm-6">
                    <label for="name" class="col-sm-12 col-form-label form-label">Name</label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="name" id="name" value="<?= htmlspecialchars($settings['name'] ?? $plugin_info['plugin_name'] ?? 'Bangla QR') ?>" readonly>
                    </div>
                    <div class="text-secondary mt-2"> </div>
                  </div>
                  <div class="col-sm-6">
                    <label for="display_name" class="col-sm-12 col-form-label form-label">Display name</label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="display_name" id="display_name" value="<?= htmlspecialchars($settings['display_name'] ?? 'Bangla QR') ?>">
                    </div>
                    <div class="text-secondary mt-2"> </div>
                  </div>
                </div>

                <div class="row mb-4">
                  <div class="col-sm-6">
                    <label for="min_amount" class="col-sm-12 col-form-label form-label">Min amount</label>
                    <div class="input-group">
                        <span class="input-group-text" id="basic-addon1">BDT</span>
                        <input type="text" class="form-control" name="min_amount" id="min_amount" value="<?= htmlspecialchars($settings['min_amount'] ?? '1') ?>">
                    </div>
                    <div class="text-secondary mt-2"> </div>
                  </div>
                  <div class="col-sm-6">
                    <label for="max_amount" class="col-sm-12 col-form-label form-label">Max amount</label>
                    <div class="input-group">
                        <span class="input-group-text" id="basic-addon1">BDT</span>
                        <input type="text" class="form-control" name="max_amount" id="max_amount" value="<?= htmlspecialchars($settings['max_amount'] ?? '50000') ?>">
                    </div>
                    <div class="text-secondary mt-2"> </div>
                  </div>
                </div>
                
                <div class="row mb-4">
                  <div class="col-sm-6">
                    <label for="fixed_charge" class="col-sm-12 col-form-label form-label">Fixed charge</label>
                    <div class="input-group">
                        <span class="input-group-text" id="basic-addon1">BDT</span>
                        <input type="text" class="form-control" name="fixed_charge" id="fixed_charge" value="<?= htmlspecialchars($settings['fixed_charge'] ?? '0') ?>">
                    </div>
                    <div class="text-secondary mt-2"> </div>
                  </div>
                    
                  <div class="col-sm-6">
                    <label for="percent_charge" class="col-sm-12 col-form-label form-label">Percent charge</label>
                    <div class="input-group">
                        <span class="input-group-text" id="basic-addon1">BDT</span>
                        <input type="text" class="form-control" name="percent_charge" id="percent_charge" value="<?= htmlspecialchars($settings['percent_charge'] ?? '0') ?>">
                    </div>
                    <div class="text-secondary mt-2"> </div>
                  </div>
                  
                  <div class="col-sm-6">
                    <label for="status" class="col-sm-12 col-form-label form-label">Status</label>
                    <div class="input-group">
                      <select class="form-control" name="status" id="status">
                        <?php $status_gateway = isset($settings['status']) ? strtolower($settings['status']) : 'enable'; ?>
                        <option value="disable" <?php echo ($status_gateway === 'disable') ? 'selected' : ''; ?>>Disable</option>
                        <option value="enable" <?php echo ($status_gateway === 'enable') ? 'selected' : ''; ?>>Enable</option>
                      </select>
                    </div>
                    <div class="text-secondary mt-2"> </div>
                  </div>
                  
                  <div class="col-sm-6">
                    <label for="category" class="col-sm-12 col-form-label form-label">Category</label>
                    <div class="input-group">
                      <input type="text" class="form-control" name="category" id="category" value="Mobile Banking" readonly>
                    </div>
                    <div class="text-secondary mt-2"> </div>
                  </div>
                  
                  <div class="col-sm-6">
                    <label for="currency" class="col-sm-12 col-form-label form-label">Currency</label>
                    <div class="input-group">
                      <input type="text" class="form-control" name="currency" id="currency" value="BDT" readonly>
                    </div>
                    <div class="text-secondary mt-2"> </div>
                  </div>
                </div>
            </div>
            <!-- End Body -->
          </div>
          
          <!-- Bangla QR Upload & Configuration Card -->
          <div class="card">
            <div class="card-header">
              <h2 class="card-title h4">Bangla QR Code & Provider</h2>
            </div>

            <!-- Body -->
            <div class="card-body">
                <div class="row mb-4">
                  <div class="col-sm-6">
                    <label for="sender_key" class="col-sm-12 col-form-label form-label">SMS Provider Name</label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="sender_key" id="sender_key" value="<?= htmlspecialchars($settings['sender_key'] ?? 'Rocket') ?>" placeholder="e.g. Rocket, bKash, Nagad">
                    </div>
                    <div class="text-secondary mt-2 small">Provider on your SMS receiver log (e.g. <b>Rocket</b>)</div>
                  </div>

                  <div class="col-sm-6">
                    <label for="timer_duration" class="col-sm-12 col-form-label form-label">Timer Window (Minutes)</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="timer_duration" id="timer_duration" value="<?= htmlspecialchars($settings['timer_duration'] ?? '15') ?>">
                    </div>
                    <div class="text-secondary mt-2 small">Default 15 minutes verification countdown</div>
                  </div>
                </div>

                <div class="row mb-4">
                  <div class="col-sm-7">
                    <label class="col-sm-12 col-form-label form-label">Upload Bangla QR Image</label>
                    <div class="mb-2">
                      <input type="file" class="form-control" id="qr_file_input" accept="image/*">
                    </div>
                    <div id="upload_alert"></div>
                    <div class="text-secondary small">
                      Select your QR image file (PNG, JPG, WebP) to upload directly and replace the default QR code.
                    </div>
                  </div>

                  <div class="col-sm-5 text-center">
                    <label class="form-label d-block">Current QR Preview</label>
                    <div class="p-2 border rounded bg-light d-inline-block">
                      <?php 
                        $qr_file_path = $plugin_base_url . '/assets/bangla-qr-default.jpg?v=' . time();
                      ?>
                      <img id="admin_qr_preview" src="<?= htmlspecialchars($qr_file_path) ?>" alt="QR Preview" style="max-width: 140px; max-height: 140px; object-fit: contain;">
                    </div>
                  </div>
                </div>
            </div>
            <!-- End Body -->
          </div>

          <div id="ajaxResponse"></div>

          <button type="submit" class="btn btn-primary btn-primary-add" style=" max-width: 150px; ">Save Settings</button>
          <!-- End Card -->
        <div id="stickyBlockEndPoint"></div>
      </div>
    </div>
</form>

<script>
    const pluginUploadUrl = '<?php echo $plugin_base_url . "/upload.php"; ?>';

    // Direct Instant Upload to upload.php as soon as file is chosen
    $(document).off('change', '#qr_file_input').on('change', '#qr_file_input', function(e) {
        const file = e.target.files[0];
        if (!file) return;

        const alertBox = document.getElementById('upload_alert');
        if (alertBox) alertBox.innerHTML = '<div class="alert alert-info py-2 mb-2"><span class="spinner-border spinner-border-sm me-2"></span>Uploading QR to server assets...</div>';

        const formData = new FormData();
        formData.append('qr_file', file);

        $.ajax({
            url: pluginUploadUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res && res.status) {
                    if (alertBox) alertBox.innerHTML = '<div class="alert alert-success py-2 mb-2"><i class="bi bi-check-circle me-1"></i>' + res.message + '</div>';
                    // Update preview with fresh timestamp cache-buster
                    const reader = new FileReader();
                    reader.onload = function(evt) {
                        const preview = document.getElementById('admin_qr_preview');
                        if (preview) preview.src = evt.target.result;
                    };
                    reader.readAsDataURL(file);
                } else {
                    if (alertBox) alertBox.innerHTML = '<div class="alert alert-danger py-2 mb-2">' + (res && res.message ? res.message : 'Upload failed') + '</div>';
                }
            },
            error: function() {
                if (alertBox) alertBox.innerHTML = '<div class="alert alert-danger py-2 mb-2">Upload failed. Please check folder permissions.</div>';
            }
        });
    });

    // Form submission handler that works across SPA / PJAX / Dynamic Views / First visits
    window.handleAdminSettingsSubmit = function(e, form) {
        if (e && e.preventDefault) e.preventDefault();

        const $form = $(form || '#smtpSettingsForm');
        const submitBtn = document.querySelector(".btn-primary-add");
        
        if (submitBtn) {
            submitBtn.innerHTML = '<div class="spinner-border text-light spinner-border-sm" role="status"> <span class="visually-hidden">Loading...</span> </div>';
            submitBtn.disabled = true;
        }
        
        const targetUrl = $form.attr('action') || window.location.href;

        $.ajax({
            url: targetUrl,
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(response) {
                if (submitBtn) {
                    submitBtn.innerHTML = 'Save Settings';
                    submitBtn.disabled = false;
                }
                
                if (response && response.status) {
                    $('#ajaxResponse').attr('class', 'alert alert-success mb-3').html(response.message || 'Settings saved successfully!');
                } else {
                    $('#ajaxResponse').attr('class', 'alert alert-danger mb-3').html((response && response.message) || 'Failed to save settings.');
                }
            },
            error: function(xhr) {
                if (submitBtn) {
                    submitBtn.innerHTML = 'Save Settings';
                    submitBtn.disabled = false;
                }
                let msg = 'An error occurred. Please try again.';
                try {
                    const parsed = JSON.parse(xhr.responseText);
                    if (parsed && parsed.message) msg = parsed.message;
                } catch(err){}
                $('#ajaxResponse').attr('class', 'alert alert-danger mb-3').html(msg);
            }
        });

        return false;
    };

    // Attach submit event listener directly on document
    $(document).off('submit', '#smtpSettingsForm').on('submit', '#smtpSettingsForm', function(e) {
        return handleAdminSettingsSubmit(e, this);
    });
</script>
