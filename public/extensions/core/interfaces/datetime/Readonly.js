var __DirectusExtension__=function(e){var t={};function n(r){if(t[r])return t[r].exports;var o=t[r]={i:r,l:!1,exports:{}};return e[r].call(o.exports,o,o.exports,n),o.l=!0,o.exports}return n.m=e,n.c=t,n.d=function(e,t,r){n.o(e,t)||Object.defineProperty(e,t,{configurable:!1,enumerable:!0,get:r})},n.r=function(e){Object.defineProperty(e,"__esModule",{value:!0})},n.n=function(e){var t=e&&e.__esModule?function(){return e.default}:function(){return e};return n.d(t,"a",t),t},n.o=function(e,t){return Object.prototype.hasOwnProperty.call(e,t)},n.p="",n(n.s=55)}({0:function(e,t,n){"use strict";function r(e,t,n,r,o,i,u,s){var l=typeof(e=e||{}).default;"object"!==l&&"function"!==l||(e=e.default);var a,c="function"==typeof e?e.options:e;if(t&&(c.render=t,c.staticRenderFns=n,c._compiled=!0),r&&(c.functional=!0),i&&(c._scopeId=i),u?(a=function(e){(e=e||this.$vnode&&this.$vnode.ssrContext||this.parent&&this.parent.$vnode&&this.parent.$vnode.ssrContext)||"undefined"==typeof __VUE_SSR_CONTEXT__||(e=__VUE_SSR_CONTEXT__),o&&o.call(this,e),e&&e._registeredComponents&&e._registeredComponents.add(u)},c._ssrRegister=a):o&&(a=s?function(){o.call(this,this.$root.$options.shadowRoot)}:o),a)if(c.functional){c._injectStyles=a;var d=c.render;c.render=function(e,t){return a.call(t),d(e,t)}}else{var f=c.beforeCreate;c.beforeCreate=f?[].concat(f,a):[a]}return{exports:e,options:c}}n.d(t,"a",function(){return r})},1:function(e,t){e.exports={props:{name:{type:String,required:!0},value:{type:null,default:null},type:{type:String,required:!0},length:{type:[String,Number],default:null},readonly:{type:Boolean,default:!1},required:{type:Boolean,default:!1},loading:{type:Boolean,default:!1},options:{type:Object,default:function(){return{}}},newItem:{type:Boolean,default:!1}}}},55:function(e,t,n){"use strict";n.r(t);var r=n(1),o={mixins:[n.n(r).a],computed:{date:function(){return this.value?this.$helpers.date.sqlToDate(this.value):null},since:function(){if(this.value){var e=this.date.toISOString();return e.substring(0,e.length-1)}return null},displayValue:function(){return this.value&&this.options.localized?this.$d(this.date,"long"):this.value}}},i=n(0),u=Object(i.a)(o,function(){var e=this.$createElement,t=this._self._c||e;return this.value&&this.options.showRelative?t("timeago",{attrs:{since:this.since,"auto-update":60,locale:this.$i18n.locale}}):t("div",[this._v(this._s(this.displayValue))])},[],!1,null,null,null);t.default=u.exports}});