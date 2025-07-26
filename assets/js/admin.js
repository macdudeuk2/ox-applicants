/**
 * OX Applicants Admin JavaScript
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        OXApplicantsAdmin.init();
    });

    // Main admin object
    var OXApplicantsAdmin = {
        
        /**
         * Initialize admin functionality
         */
        init: function() {
            this.bindEvents();
            this.initStatusUpdates();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Status form submission
            $(document).on('submit', '.ox-status-form', this.handleStatusFormSubmit);
            
            // Status select change
            $(document).on('change', '#application_status', this.handleStatusChange);
        },

        /**
         * Initialize status update functionality
         */
        initStatusUpdates: function() {
            // Add loading states to forms
            $('.ox-status-form').each(function() {
                var $form = $(this);
                var $submitBtn = $form.find('input[type="submit"]');
                
                if ($submitBtn.length) {
                    $submitBtn.data('original-text', $submitBtn.val());
                }
            });
        },

        /**
         * Handle status form submission
         */
        handleStatusFormSubmit: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $form.find('input[type="submit"]');
            var applicationId = $form.find('input[name="application_id"]').val();
            var newStatus = $form.find('select[name="application_status"]').val();
            
            if (!applicationId || !newStatus) {
                OXApplicantsAdmin.showNotice('Error: Missing required data.', 'error');
                return false;
            }

            // Show loading state
            $submitBtn.addClass('ox-loading').val('Updating...');
            $form.addClass('ox-loading');

            // Send AJAX request
            $.ajax({
                url: oxApplicantsAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ox_update_application_status',
                    nonce: oxApplicantsAdmin.nonce,
                    application_id: applicationId,
                    status: newStatus
                },
                success: function(response) {
                    if (response.success) {
                        OXApplicantsAdmin.showNotice(response.data, 'success');
                        
                        // Update status badge if it exists
                        var $statusBadge = $('.ox-application-status .ox-status-badge');
                        if ($statusBadge.length) {
                            var statusLabels = {
                                'new': 'New',
                                'on_hold': 'On Hold',
                                'accepted': 'Accepted',
                                'rejected': 'Rejected'
                            };
                            var statusClasses = {
                                'new': 'status-new',
                                'on_hold': 'status-on-hold',
                                'accepted': 'status-accepted',
                                'rejected': 'status-rejected'
                            };
                            
                            $statusBadge
                                .removeClass()
                                .addClass('ox-status-badge ' + statusClasses[newStatus])
                                .text(statusLabels[newStatus] || newStatus);
                        }
                        
                        // Reload page after a short delay to show updated data
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                        
                    } else {
                        OXApplicantsAdmin.showNotice(response.data || 'Error updating status.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    OXApplicantsAdmin.showNotice('Network error: ' + error, 'error');
                },
                complete: function() {
                    // Remove loading state
                    $submitBtn.removeClass('ox-loading').val($submitBtn.data('original-text'));
                    $form.removeClass('ox-loading');
                }
            });
        },

        /**
         * Handle status select change
         */
        handleStatusChange: function() {
            var newStatus = $(this).val();
            var currentStatus = $(this).data('current-status');
            
            if (newStatus === currentStatus) {
                return;
            }
            
            // Show confirmation for status changes
            if (newStatus === 'rejected' || newStatus === 'accepted') {
                var message = newStatus === 'rejected' 
                    ? 'Are you sure you want to reject this application? This action cannot be undone.'
                    : 'Are you sure you want to accept this application? This will create a subscription and change the user role.';
                
                if (!confirm(message)) {
                    $(this).val(currentStatus);
                    return;
                }
            }
        },

        /**
         * Show notice message
         */
        showNotice: function(message, type) {
            type = type || 'info';
            
            var $notice = $('<div class="ox-notice ox-notice-' + type + '">' + message + '</div>');
            
            // Remove existing notices
            $('.ox-notice').remove();
            
            // Add new notice at the top of the page
            $('.wrap h1').after($notice);
            
            // Auto-remove after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Show loading overlay
         */
        showLoading: function() {
            if ($('#ox-loading-overlay').length === 0) {
                $('body').append('<div id="ox-loading-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); z-index: 9999; display: flex; align-items: center; justify-content: center;"><div style="text-align: center;"><div style="width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #0073aa; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 10px;"></div><p>Processing...</p></div></div>');
            }
            $('#ox-loading-overlay').show();
        },

        /**
         * Hide loading overlay
         */
        hideLoading: function() {
            $('#ox-loading-overlay').hide();
        }
    };

    // Make it globally available
    window.OXApplicantsAdmin = OXApplicantsAdmin;

})(jQuery); 