jQuery(document).ready(function($){
    
    //form buttongs
    $('#pinim-form-login #submit').click(function(e) {
        var button = $(this);
        var form = button.parents('.pinim-form');
        var form_id = form.attr('id');
        var form_data = {};
        //form_data._wpnonce=getURLParameter(link.attr('href'),'_wpnonce');

        e.preventDefault();
        
        
        switch(form_id) {
            case 'pinim-form-login':
                form_data.login = 'XXX';
                form_data.password = 'XXX';
                form_data.action='pinim_login';
            break;
        }
        
        console.log(form_data);
        
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
    
    //handle board categories
    var boardCats = $('#the-list .column-category input[type="radio"]');
    boardCats.pinimBoardCats(); //init
    boardCats.click(function(e) {
        $(this).pinimBoardCats();
    });
    
    //update pins confirm
    $('.row-actions .update a, .tablenav #update_all_bt').click(function(e) {
        r = confirm(pinimL10n.update_warning);
        if (r == false) {
            e.preventDefault();
        }
    });
    $('.tablenav .bulkactions #doaction').click(function(e) {
        var container = $(this).parents('.bulkactions');
        var select = container.find('select');
        var selected = select.val();
        if (selected == 'pins_update_pins'){
            r = confirm(pinimL10n.update_warning);
            if (r == false) {
                e.preventDefault();
            }
        }
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

