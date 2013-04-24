
(function(){

    // Simple perfect bar chart
    // -------------------------

    var DataTable = Datawrapper.Visualizations.DataTable = function() {

    };

    _.extend(DataTable.prototype, Datawrapper.Visualizations.Base, {

        render: function(el) {
            el = $(el);
            // add table
            var me = this, table, tr, td, th, r,
                isHighlighted = function(series) {
                    return me.chart.hasHighlight() && me.chart.isHighlighted(series);
                };
            table = $('<table id="datatable"><thead /><tbody /></table>');
            tr = $('<tr />');
            if (me.chart.hasRowHeader()) {
                var h = me.dataset.rowNameLabel();
                if (/^X\.\d+$/.test(h)) h = '';
                tr.append('<th>'+h+'</tr>');
            }
            var colType = [];
            _.each(me.chart.dataSeries(), function(series) {
                th = $('<th>'+series.name+'</th>');
                if (isHighlighted(series)) {
                    th.addClass('highlight');
                }
                /*if (series.type.substr(0,14) == 'number-decimal') {
                    colType.push('number-decimal');
                    th.addClass('number-decimal');
                } else if (series.type == 'number') {*/
                    // check for small numbers
                var small = true;
                _.each(series.data, function(val) {
                    small = small && val <= 100 && val >= -100;
                });
                colType.push('number'+(small ? '-small' : ''));
                th.addClass('number'+(small ? '-small' : ''));
                //}

                tr.append(th);
            });
            $('thead', table).append(tr);
            for (r = 0; r < me.chart.numRows(); r++) {
                tr = $('<tr />');
                var highlighted_rows = me.get('highlighted-rows');
                if (me.chart.hasRowHeader()) {
                    tr.append('<th>'+me.chart.rowLabel(r)+'</th>');
                    // Highlight the row
                    if (_.isArray(highlighted_rows) && _.indexOf(highlighted_rows, me.chart.rowLabel(r)) >= 0) {
                        tr.addClass('highlight');
                    }
                } else { // Highlight the row
                         // In this case, the chart has not row header, the value of me.get('table-highlight-row')
                         // is like "Row <line number starting from 1>" (see rowLabels's definition in dw.chart.js)
                    if (_.isArray(highlighted_rows) && _.indexOf(highlighted_rows, "Row "+(me.chart.rowLabel(r)+1)) >= 0) {
                        tr.addClass('highlight');
                    }
                }
                _.each(me.chart.dataSeries(), function(series, s) {
                    td = $('<td>'+me.chart.formatValue(series.data[r], true)+'</td>');
                    if (isHighlighted(series)) {
                        td.addClass('highlight');
                    }
                    td.addClass(colType[s]);
                    td.attr('title', series.name);
                    tr.append(td);
                });
                $('tbody', table).append(tr);
            }
            el.append(table);

            if (me.get('table-responsive')) {
                table.addClass('responsive');
            }

            table.dataTable({
                "bPaginate": me.get('table-paginate', false),
                "bInfo": me.get('table-paginate', false),
                "bFilter": me.get('table-filter', false),
                "bSort": me.get('table-sortable', false)
            });

            el.append('<br style="clear:both"/>');
        }

    });

}).call(this);