jQuery(document).ready(function($){
    
    //new boards row
    $('.pinim-form-boards .column-new_board').hide();
    $('.pinim-form-boards table.wp-list-table tbody tr').each(function() {
       var row = $(this);
       var bulk = $(this).find("input.bulk");
       var form_options = $(this).find("input,select").not('.bulk');

        form_options.change(function() {
          bulk.prop('checked', true);
          bulk.trigger("change");
        });
        
        bulk.change(function() {
          row.toggleClass("is-checked", this.checked);
        });

    });
    
    //form buttons
    /*
    $('#pinim-form-login #submit').click(function(e) {
        var button = $(this);
        var form = button.parents('.pinim-form');
        var form_id = form.attr('id');
        var form_data = {};
        //form_data._wpnonce=getURLParameter(link.attr('href'),'_wpnonce');

        e.preventDefault();
        
        
        switch(form_id) {
            
            case 'pinim-form-login':
                form_data.login = $('input[name="pinim_form_login[username]"]').val();
                form_data.password = $('input[name="pinim_form_login[password]"]').val();
                form_data.action='pinim_login';
            break;
        }
        
        console.log(form_data);
        return;
        
        $.ajax({
    
            type: "post",
            url: pinimL10n.ajaxurl,
            data:form_data,
            dataType: 'json',
            beforeSend: function() {
                form.addClass('pinim-form-loading');
            },
            success: function(data){
                
                console.log(data);

                if (data.success === false) {
                    form.addClass('pinim-form-error');
                    console.log(data.message);
                }else if (data.success === true) {
                    form.addClass('pinim-form-success');
                }


            },
            error: function (xhr, ajaxOptions, thrownError) {
                form.addClass('pinim-form-error');
                console.log(xhr.status);
                console.log(thrownError);
            },
            complete: function() {
                form.removeClass('pinim-form-loading');
            }
        });

        return false;  
        
    });
    */
    
    //handle board categories
    var boardCats = $('#the-list .column-category input[type="radio"]');
    boardCats.pinimBoardCats(); //init
    boardCats.click(function(e) {
        $(this).pinimBoardCats();
    });


});


(function($) {
    $.fn.pinimBoardCats = function() {
        return this.each(function() {
            var cell = $( this ).parents('.column-category');
            var auto = cell.find('input[type="radio"][value="auto"]');
            var select = cell.find('select');
            if (auto.is(':checked')){
                select.addClass('hidden');
            }else{
                select.removeClass('hidden');
            }
        });
    };
}(jQuery));

