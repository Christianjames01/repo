/**
 * BarangayLink Custom JavaScript
 */

$(document).ready(function() {
    'use strict';

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // File input display filename
    $('.custom-file-input').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').html(fileName);
    });

    // Confirm before form submission
    $('.confirm-submit').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        Swal.fire({
            title: 'Confirm Action',
            text: 'Are you sure you want to proceed?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, proceed',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                form.unbind('submit').submit();
            }
        });
    });

    // Auto-grow textarea
    $('textarea.auto-grow').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    // Number input validation
    $('.number-only').on('keypress', function(e) {
        var charCode = (e.which) ? e.which : e.keyCode;
        if (charCode > 31 && (charCode < 48 || charCode > 57)) {
            return false;
        }
        return true;
    });

    // Phone number formatting
    $('.phone-format').on('input', function() {
        var value = $(this).val().replace(/\D/g, '');
        if (value.length > 0) {
            if (value.startsWith('639')) {
                value = '+' + value;
            } else if (value.startsWith('09')) {
                // Keep as is
            }
        }
        $(this).val(value);
    });

    // Print functionality
    $('.btn-print').on('click', function() {
        window.print();
    });

    // Export to CSV
    $('.btn-export-csv').on('click', function() {
        var table = $(this).data('table');
        exportTableToCSV(table, 'export.csv');
    });

    // Copy to clipboard
    $('.btn-copy').on('click', function() {
        var text = $(this).data('text');
        navigator.clipboard.writeText(text).then(function() {
            Swal.fire({
                icon: 'success',
                title: 'Copied!',
                text: 'Text copied to clipboard',
                timer: 1500,
                showConfirmButton: false
            });
        });
    });

    // Search with debounce
    var searchTimeout;
    $('.search-input').on('input', function() {
        clearTimeout(searchTimeout);
        var input = $(this);
        searchTimeout = setTimeout(function() {
            // Perform search
            var query = input.val();
            console.log('Searching for: ' + query);
        }, 500);
    });

    // Image preview before upload
    $('.image-input').on('change', function() {
        var input = this;
        var preview = $(this).data('preview');
        
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            
            reader.onload = function(e) {
                $(preview).attr('src', e.target.result);
                $(preview).show();
            };
            
            reader.readAsDataURL(input.files[0]);
        }
    });

    // Confirm delete with SweetAlert
    $('.btn-delete').on('click', function(e) {
        e.preventDefault();
        var url = $(this).attr('href');
        var title = $(this).data('title') || 'Confirm Deletion';
        var message = $(this).data('message') || 'Are you sure you want to delete this item?';
        
        Swal.fire({
            title: title,
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    });

    // Form validation
    $('.needs-validation').on('submit', function(e) {
        if (!this.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        $(this).addClass('was-validated');
    });

    // Dynamic form rows
    var rowCount = 1;
    $('.btn-add-row').on('click', function() {
        var container = $(this).data('container');
        var template = $(this).data('template');
        var newRow = $(template).clone();
        newRow.find('input, select, textarea').val('');
        newRow.find('.row-number').text(++rowCount);
        $(container).append(newRow);
    });

    $(document).on('click', '.btn-remove-row', function() {
        $(this).closest('.dynamic-row').remove();
        updateRowNumbers();
    });

    function updateRowNumbers() {
        $('.dynamic-row').each(function(index) {
            $(this).find('.row-number').text(index + 1);
        });
    }

    // Date range picker initialization
    if ($.fn.daterangepicker) {
        $('.date-range').daterangepicker({
            autoUpdateInput: false,
            locale: {
                format: 'YYYY-MM-DD',
                cancelLabel: 'Clear'
            }
        });

        $('.date-range').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('YYYY-MM-DD') + ' to ' + picker.endDate.format('YYYY-MM-DD'));
        });

        $('.date-range').on('cancel.daterangepicker', function(ev, picker) {
            $(this).val('');
        });
    }

    // Smooth scroll to top
    $('.scroll-to-top').on('click', function() {
        $('html, body').animate({ scrollTop: 0 }, 600);
        return false;
    });

    // Auto-save form
    var autoSaveTimeout;
    $('.auto-save-form input, .auto-save-form textarea, .auto-save-form select').on('change', function() {
        clearTimeout(autoSaveTimeout);
        autoSaveTimeout = setTimeout(function() {
            // Implement auto-save logic here
            console.log('Form auto-saved');
        }, 2000);
    });

    // Real-time character counter
    $('.char-counter').on('input', function() {
        var maxLength = $(this).attr('maxlength');
        var currentLength = $(this).val().length;
        var counter = $(this).data('counter');
        $(counter).text(currentLength + ' / ' + maxLength);
    });

    // Toggle password visibility
    $('.toggle-password').on('click', function() {
        var input = $($(this).data('target'));
        var icon = $(this).find('i');
        
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });
});

// Helper function to export table to CSV
function exportTableToCSV(tableId, filename) {
    var csv = [];
    var rows = document.querySelectorAll(tableId + ' tr');
    
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (var j = 0; j < cols.length; j++) {
            row.push(cols[j].innerText);
        }
        
        csv.push(row.join(','));
    }
    
    downloadCSV(csv.join('\n'), filename);
}

function downloadCSV(csv, filename) {
    var csvFile = new Blob([csv], { type: 'text/csv' });
    var downloadLink = document.createElement('a');
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// Format number as currency
function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

// Validate Philippine mobile number
function isValidPhilippineMobile(number) {
    var pattern = /^(09|\+639)\d{9}$/;
    return pattern.test(number);
}

// Calculate age from birthdate
function calculateAge(birthdate) {
    var today = new Date();
    var birth = new Date(birthdate);
    var age = today.getFullYear() - birth.getFullYear();
    var m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
        age--;
    }
    return age;
}

// Format date to readable format
function formatDate(dateString) {
    var date = new Date(dateString);
    var options = { year: 'numeric', month: 'long', day: 'numeric' };
    return date.toLocaleDateString('en-US', options);
}

// Show loading overlay
function showLoading() {
    Swal.fire({
        title: 'Please wait...',
        text: 'Processing your request',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
}

// Hide loading overlay
function hideLoading() {
    Swal.close();
}