const fetch = require("node-fetch");
import React from "react";


import { ResponsiveBar } from '@nivo/bar'

import ReactTable from "react-table";
import "react-table/react-table.css";
import ReactPaginate from 'react-paginate';
import Router from 'next/router'
import dayjs from 'dayjs'
import C3Chart from 'react-c3js';
import Head from 'next/head';
import 'c3/c3.css';
Number.prototype.format = function(n, x, s, c) {
  var re = '\\d(?=(\\d{' + (x || 3) + '})+' + (n > 0 ? '\\D' : '$') + ')',
      num = this.toFixed(Math.max(0, ~~n));

  return (c ? num.replace('.', c) : num).replace(new RegExp(re, 'g'), '$&' + (s || ','));
};

export default class extends React.Component {
   constructor(props) {
      super(props);      
      this.state = {year: null}
   }
  static async getInitialProps({ req, query, params }) {

    const res = await fetch("http://pcc.mlwmlw.org/api/unit/" + encodeURIComponent(query.unit));
    const data = await res.json()

  
    let units = {}
    for(var i in data) {
      if(!data[i].award)
        continue;
      for(var j in data[i].award.merchants) {
        var merchant = data[i].award.merchants[j];
        if(!units[merchant.name])
          units[merchant.name] = 0;
        units[merchant.name] += merchant.amount
      }
    }
    var stats = [];
    var inc = 0;
    for (var i in units) {
      
        stats.push({
          index: inc++,
          name: i,
          price: units[i]
        });
    }
    stats.sort(function(a, b) {
        return b.price - a.price;
    });
    
    return { stats: stats.slice(0, 13), data, unit: query.unit };
  }
 
  render() {
    let { data, unit, stats }= this.props;
   let title = unit + '標案檢索'
   let desc = unit + " 最新標案 ";
   var inc = 0;

   data.slice(0, 5).map(function(row) {
     
    desc += dayjs(row.publish).format('YYYY-MM-DD') + " " + row.name + " 金額 $" + row.price.format(0, 3, ',') +"、";
   })
    
    return (
      <div className="starter-template">
        <Head>
        <title>{title} - 開放政府標案</title>
        <meta property="og:description"
        content={desc}/>
        </Head>
        <h1>{title}</h1>

        <h2>累積得標金額廠商排行</h2>
        <div style={{width: "100%", height: "400px"}}>
        <ResponsiveBar data={stats}

          axisBottom={{
            "tickSize": 5,
            "tickPadding": 5,
            "tickRotation": 45,
            
            "legendPosition": "middle",
            "legendOffset": 32
          }}
          indexBy="name"
          keys={["price"]}
          margin={{
              "top": 30,
              "right": 200,
              "bottom": 150,
              "left": 0
          }}
          enableLabel={false}
          sortByValue={true}
          innerRadius={0.5}
          padAngle={0.7}
          cornerRadius={3}
          colors="paired"
          colorBy="index"
          borderWidth={1}
          borderColor="inherit:darker(0.2)"
          animate={true}
          motionStiffness={90}
          motionDamping={15}
          
          legends={[
            {
                "dataFrom": "indexes",
                "anchor": "bottom-right",
                "direction": "column",
                "translateX": 150,
                "itemWidth": 140,
                "itemHeight": 16,
                "itemsSpacing": 2,
                "itemTextColor": "#666",
                "symbolSize": 15,
                "symbolShape": "circle",
                "effects": [
                    {
                        "on": "hover",
                        "style": {
                            "itemTextColor": "#000"
                        }
                    }
                ]
            }
          ]}
        />
        </div>
        <ReactTable
          data={data}
          columns={[
            
            {
              Header: "標案名稱",
              accessor: "name"
            },
            {
              Header: "標案金額",
              accessor: "price"
            },
            {
              Header: "招標/決標日期",
              accessor: "publish",
              Cell: ({row}) => {
                let date = dayjs(row.publish).format('YYYY-MM-DD');
                return date;
              }
            },
            {
              Header: "得標廠商",
              accessor: "award",
              Cell: ({row}) => {
                var merchants = row.award && row.award.merchants ;
                
                if (typeof merchants)
                    if (merchants && Object.keys(merchants).length) {
                        var $merchants = [];
                        Object.keys(merchants).map(function(k) {
                            var m = merchants[k];
                            $merchants.push(
                                <li key={k}><a href={'/merchants/' + (m._id || m.name) }>{m.name}</a></li>
                            );
                        });
                        return <ul>{$merchants}</ul>
                    } else if (merchants && merchants.length == 0) {
                    return '無法決標';
                } else {
                    return '';
                }
              }
            },
            {
              Header: "原始公告",
              accessor: "filename",
              filterable: false,
              Cell: ({ row }) => {                
                let title = '', url = '';
                let date = dayjs(row.publish).format('YYYYMMDD');
                title = "招標公告";
                url = `//web.pcc.gov.tw/prkms/prms-viewTenderDetailClient.do?ds=${date}&fn=${row.filename}.xml`
                return <a target="_blank" href={url}>
                  {title}
                </a>
              }
            }
          ]}
         
          defaultPageSize={100}
          pageSizeOptions={[100, 500]}
          className="-striped -highlight"
        />
        
        
      </div>
    );
  }
}