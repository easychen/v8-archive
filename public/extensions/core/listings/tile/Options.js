var __DirectusExtension__=function(t){var e={};function n(r){if(e[r])return e[r].exports;var o=e[r]={i:r,l:!1,exports:{}};return t[r].call(o.exports,o,o.exports,n),o.l=!0,o.exports}return n.m=t,n.c=e,n.d=function(t,e,r){n.o(t,e)||Object.defineProperty(t,e,{configurable:!1,enumerable:!0,get:r})},n.r=function(t){Object.defineProperty(t,"__esModule",{value:!0})},n.n=function(t){var e=t&&t.__esModule?function(){return t.default}:function(){return t};return n.d(e,"a",e),e},n.o=function(t,e){return Object.prototype.hasOwnProperty.call(t,e)},n.p="",n(n.s=5)}([function(t,e,n){"use strict";function r(t,e,n,r,o,i,s,a){var u=typeof(t=t||{}).default;"object"!==u&&"function"!==u||(t=t.default);var l,c="function"==typeof t?t.options:t;if(e&&(c.render=e,c.staticRenderFns=n,c._compiled=!0),r&&(c.functional=!0),i&&(c._scopeId=i),s?(l=function(t){(t=t||this.$vnode&&this.$vnode.ssrContext||this.parent&&this.parent.$vnode&&this.parent.$vnode.ssrContext)||"undefined"==typeof __VUE_SSR_CONTEXT__||(t=__VUE_SSR_CONTEXT__),o&&o.call(this,t),t&&t._registeredComponents&&t._registeredComponents.add(s)},c._ssrRegister=l):o&&(l=a?function(){o.call(this,this.$root.$options.shadowRoot)}:o),l)if(c.functional){c._injectStyles=l;var f=c.render;c.render=function(t,e){return l.call(e),f(t,e)}}else{var p=c.beforeCreate;c.beforeCreate=p?[].concat(p,l):[l]}return{exports:t,options:c}}n.d(e,"a",function(){return r})},function(t,e){t.exports={props:{primaryKeyField:{type:String,required:!0},fields:{type:Object,required:!0},items:{type:Array,required:!0},options:{type:Object,default:function(){return{}}},loading:{type:Boolean,default:!1},query:{type:Object,default:function(){return{}}},selection:{type:Array,default:function(){return[]}},link:{type:String,default:null}}}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.default=function(t,e){for(var n=[],r={},o=0;o<e.length;o++){var i=e[o],s=i[0],a={id:t+":"+o,css:i[1],media:i[2],sourceMap:i[3]};r[s]?r[s].parts.push(a):n.push(r[s]={id:s,parts:[a]})}return n}},function(t,e,n){"use strict";n.r(e),n.d(e,"default",function(){return h});var r=n(2),o=n.n(r),i="undefined"!=typeof document;if("undefined"!=typeof DEBUG&&DEBUG&&!i)throw new Error("vue-style-loader cannot be used in a non-browser environment. Use { target: 'node' } in your Webpack config to indicate a server-rendering environment.");var s={},a=i&&(document.head||document.getElementsByTagName("head")[0]),u=null,l=0,c=!1,f=function(){},p=null,d="data-vue-ssr-id",v="undefined"!=typeof navigator&&/msie [6-9]\b/.test(navigator.userAgent.toLowerCase());function h(t,e,n,r){c=n,p=r||{};var i=o()(t,e);return _(i),function(e){for(var n=[],r=0;r<i.length;r++){var a=i[r];(u=s[a.id]).refs--,n.push(u)}for(e?_(i=o()(t,e)):i=[],r=0;r<n.length;r++){var u;if(0===(u=n[r]).refs){for(var l=0;l<u.parts.length;l++)u.parts[l]();delete s[u.id]}}}}function _(t){for(var e=0;e<t.length;e++){var n=t[e],r=s[n.id];if(r){r.refs++;for(var o=0;o<r.parts.length;o++)r.parts[o](n.parts[o]);for(;o<n.parts.length;o++)r.parts.push(g(n.parts[o]));r.parts.length>n.parts.length&&(r.parts.length=n.parts.length)}else{var i=[];for(o=0;o<n.parts.length;o++)i.push(g(n.parts[o]));s[n.id]={id:n.id,refs:1,parts:i}}}}function y(){var t=document.createElement("style");return t.type="text/css",a.appendChild(t),t}function g(t){var e,n,r=document.querySelector("style["+d+'~="'+t.id+'"]');if(r){if(c)return f;r.parentNode.removeChild(r)}if(v){var o=l++;r=u||(u=y()),e=O.bind(null,r,o,!1),n=O.bind(null,r,o,!0)}else r=y(),e=function(t,e){var n=e.css,r=e.media,o=e.sourceMap;if(r&&t.setAttribute("media",r),p.ssrId&&t.setAttribute(d,e.id),o&&(n+="\n/*# sourceURL="+o.sources[0]+" */",n+="\n/*# sourceMappingURL=data:application/json;base64,"+btoa(unescape(encodeURIComponent(JSON.stringify(o))))+" */"),t.styleSheet)t.styleSheet.cssText=n;else{for(;t.firstChild;)t.removeChild(t.firstChild);t.appendChild(document.createTextNode(n))}}.bind(null,r),n=function(){r.parentNode.removeChild(r)};return e(t),function(r){if(r){if(r.css===t.css&&r.media===t.media&&r.sourceMap===t.sourceMap)return;e(t=r)}else n()}}var m,b=(m=[],function(t,e){return m[t]=e,m.filter(Boolean).join("\n")});function O(t,e,n,r){var o=n?"":r.css;if(t.styleSheet)t.styleSheet.cssText=b(e,o);else{var i=document.createTextNode(o),s=t.childNodes;s[e]&&t.removeChild(s[e]),s.length?t.insertBefore(i,s[e]):t.appendChild(i)}}},function(t,e){t.exports=function(t){var e=[];return e.toString=function(){return this.map(function(e){var n=function(t,e){var n,r=t[1]||"",o=t[3];if(!o)return r;if(e&&"function"==typeof btoa){var i=(n=o,"/*# sourceMappingURL=data:application/json;charset=utf-8;base64,"+btoa(unescape(encodeURIComponent(JSON.stringify(n))))+" */"),s=o.sources.map(function(t){return"/*# sourceURL="+o.sourceRoot+t+" */"});return[r].concat(s).concat([i]).join("\n")}return[r].join("\n")}(e,t);return e[2]?"@media "+e[2]+"{"+n+"}":n}).join("")},e.i=function(t,n){"string"==typeof t&&(t=[[null,t,""]]);for(var r={},o=0;o<this.length;o++){var i=this[o][0];"number"==typeof i&&(r[i]=!0)}for(o=0;o<t.length;o++){var s=t[o];"number"==typeof s[0]&&r[s[0]]||(n&&!s[2]?s[2]=n:n&&(s[2]="("+s[2]+") and ("+n+")"),e.push(s))}},e}},function(t,e,n){"use strict";n.r(e);var r=n(1),o=n.n(r),i=Object.assign||function(t){for(var e=1;e<arguments.length;e++){var n=arguments[e];for(var r in n)Object.prototype.hasOwnProperty.call(n,r)&&(t[r]=n[r])}return t},s={mixins:[o.a],computed:{fieldOptions:function(){return i({__none__:"("+this.$t("dont_show")+")"},this.$lodash.mapValues(this.fields,function(t){return t.name}))},fileOptions:function(){var t=this.$lodash.filter(this.fields,function(t){return"file"===t.type.toLowerCase()}),e=this.$lodash.keyBy(t,"field"),n=i({__none__:"("+this.$t("dont_show")+")"},this.$lodash.mapValues(e,function(t){return t.name}));if("storage"in this.fields){var r=this.fields.storage;"alias"===r.type.toLowerCase()&&"directus_files"===r.collection&&(n.storage=this.$t("file"))}return n}},methods:{setOption:function(t,e){this.$emit("input",i({},this.options,function(t,e,n){return e in t?Object.defineProperty(t,e,{value:n,enumerable:!0,configurable:!0,writable:!0}):t[e]=n,t}({},t,e)))}}},a=n(0),u=Object(a.a)(s,function(){var t=this,e=t.$createElement,n=t._self._c||e;return n("form",{on:{submit:function(t){t.preventDefault()}}},[n("label",{attrs:{for:"title"}},[t._v(t._s(t.$t("listings-tile-title")))]),t._v(" "),n("v-select",{attrs:{id:"title",value:t.options.title,options:t.fieldOptions,placeholder:t.$t("select_field")},on:{input:function(e){t.setOption("title","__none__"===e?null:e)}}}),t._v(" "),n("label",{attrs:{for:"subtitle"}},[t._v(t._s(t.$t("listings-tile-subtitle")))]),t._v(" "),n("v-select",{attrs:{id:"subtitle",value:t.options.subtitle,options:t.fieldOptions,placeholder:t.$t("select_field")},on:{input:function(e){t.setOption("subtitle","__none__"===e?null:e)}}}),t._v(" "),n("label",{attrs:{for:"src"}},[t._v(t._s(t.$t("listings-tile-src")))]),t._v(" "),n("v-select",{attrs:{id:"src",value:t.options.src,options:t.fileOptions,placeholder:t.$t("select_field")},on:{input:function(e){t.setOption("src","__none__"===e?null:e)}}}),t._v(" "),n("label",{attrs:{for:"content"}},[t._v(t._s(t.$t("listings-tile-content")))]),t._v(" "),n("v-select",{attrs:{id:"content",value:t.options.content,options:t.fieldOptions,placeholder:t.$t("select_field")},on:{input:function(e){t.setOption("content","__none__"===e?null:e)}}})],1)},[],!1,function(t){n(10)},"data-v-66010f34",null);e.default=u.exports},,,,function(t,e,n){(t.exports=n(4)(!1)).push([t.i,"label[data-v-66010f34]:not(:first-of-type){margin-top:20px}",""])},function(t,e,n){var r=n(9);"string"==typeof r&&(r=[[t.i,r,""]]),r.locals&&(t.exports=r.locals),(0,n(3).default)("ff16ce16",r,!0,{})}]);