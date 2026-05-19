jQuery(document).ready(function ($) {
    var currentStep = 1;
    var wizardData = {
        template_id: '',
        is_institutional: 'no',
        membership_number: '',
        user_id: 0,
        full_name: '',
        email: '',
        is_main: false
    };

    // Load templates on page load
    function loadTemplates() {
        $.post(acmqr_ajax.ajax_url, {
            action: 'acmqr_get_templates',
            nonce: acmqr_ajax.nonce
        }, function (resp) {
            if (resp.success) {
                var $select = $('#template_id');
                $select.empty();
                $select.append('<option value="">Select template</option>');
                $.each(resp.data, function (i, tmpl) {
                    $select.append('<option value="' + tmpl.id + '">' + tmpl.title + '</option>');
                });
            } else {
                alert('Failed to load templates');
            }
        });
    }
    loadTemplates();

    // Show/hide membership/guest fields
    $('input[name="is_institutional"]').on('change', function () {
        var val = $(this).val();
        wizardData.is_institutional = val;
        if (val === 'yes') {
            $('#membership_row').show();
            $('#guest_row').hide();
        } else {
            $('#membership_row').hide();
            $('#guest_row').show();
            wizardData.membership_number = '';
            $('#membership_number').val('');
            $('#profile_feedback').empty();
            wizardData.user_id = 0;
        }
    }).trigger('change');

    // Fetch profile via AJAX
    $('#fetch_profile_btn').on('click', function () {
        var membership = $('#membership_number').val();
        if (!membership) {
            alert('Enter membership number');
            return;
        }
        $('#profile_feedback').text('Fetching...');
        $.post(acmqr_ajax.ajax_url, {
            action: 'acmqr_fetch_profile',
            membership_number: membership,
            nonce: acmqr_ajax.nonce
        }, function (resp) {
            if (resp.success) {
                wizardData.user_id = resp.data.user_id;
                wizardData.full_name = resp.data.full_name;
                wizardData.email = resp.data.email;
                wizardData.membership_number = membership;
                $('#profile_feedback').html('<span style="color:green;">✓ User found: ' + resp.data.full_name + ' (' + resp.data.email + ')</span>');
                // Auto-fill step2 fields when we go there
            } else {
                $('#profile_feedback').html('<span style="color:red;">' + resp.data + '</span>');
                wizardData.user_id = 0;
            }
        });
    });

    // Step 1 -> Step 2
    $('#step1_next').on('click', function () {
        var template = $('#template_id').val();
        if (!template) {
            alert('Please select a template');
            return;
        }
        wizardData.template_id = template;
        if (wizardData.is_institutional === 'yes') {
            if (!wizardData.user_id) {
                alert('Please fetch a valid institutional profile first.');
                return;
            }
        } else {
            var guestEmail = $('#guest_email').val();
            if (!guestEmail || !isValidEmail(guestEmail)) {
                alert('Please enter a valid guest email.');
                return;
            }
            wizardData.email = guestEmail;
            wizardData.full_name = ''; // will ask in step2
        }

        // Build step2 fields dynamically
        var step2Html = '<table class="form-table">';
        step2Html += '<tr><th>Full Name</th><td><input type="text" name="full_name" id="full_name" value="' + esc_attr(wizardData.full_name) + '" required></td></tr>';
        step2Html += '<tr><th>Email</th><td><input type="email" name="email" id="email" value="' + esc_attr(wizardData.email) + '" required></td></tr>';
        step2Html += '</table>';
        $('#user_details_fields').html(step2Html);

        showStep(2);
    });

    $('#step2_back').on('click', function () { showStep(1); });
    $('#step3_new').on('click', function () { resetWizard(); });

    // Generate certificate
    $('#generate_cert_btn').on('click', function () {
        var fullName = $('#full_name').val();
        var email = $('#email').val();
        if (!fullName || !email) {
            alert('Please fill name and email');
            return;
        }
        wizardData.full_name = fullName;
        wizardData.email = email;
        wizardData.is_main = $('input[name="is_main"]').is(':checked');

        var data = {
            action: 'acmqr_generate_certificate',
            nonce: acmqr_ajax.nonce,
            template_id: wizardData.template_id,
            is_main: wizardData.is_main,
            full_name: wizardData.full_name,
            email: wizardData.email,
            is_institutional: wizardData.is_institutional,
            user_id: wizardData.user_id
        };

        $('#generate_cert_btn').prop('disabled', true).text('Generating...');
        $.post(acmqr_ajax.ajax_url, data, function (resp) {
            if (resp.success) {
                $('#success_message').html('<div class="notice notice-success"><p>' + resp.data.message + '</p></div>');
                $('#cert_actions').html('<a href="' + resp.data.preview_url + '" target="_blank" class="button button-primary">View / Print Certificate</a>');
                showStep(3);
            } else {
                alert('Error: ' + resp.data);
            }
        }).always(function () {
            $('#generate_cert_btn').prop('disabled', false).text('Generate Certificate');
        });
    });

    function showStep(step) {
        currentStep = step;
        $('.step-content').removeClass('active');
        $('#step' + step).addClass('active');
        $('.wizard-steps .step').removeClass('active');
        $('.wizard-steps .step[data-step="' + step + '"]').addClass('active');
    }

    function resetWizard() {
        wizardData = {
            template_id: '', is_institutional: 'no', membership_number: '',
            user_id: 0, full_name: '', email: '', is_main: false
        };
        $('#step1-form')[0].reset();
        $('#step2-form')[0].reset();
        $('#guest_email').val('');
        $('#membership_number').val('');
        $('#profile_feedback').empty();
        showStep(1);
    }

    function esc_attr(str) { return $('<div />').text(str).html(); }
    function isValidEmail(email) { return /^[^\s@]+@([^\s@.,]+\.)+[^\s@.,]{2,}$/.test(email); }
});