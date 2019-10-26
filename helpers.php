<?php

// fixes the weird issue when a variable is prefixed with REQUEST_
function getVar($key) {
  $prefix = "REDIRECT_";
  if(array_key_exists($key, $_SERVER))
  return $_SERVER[$key];
  foreach($_SERVER as $k=>$v) {
    if(substr($k, 0, strlen($prefix)) == $prefix) {
    if(substr($k, -(strlen($key))) == $key)
      return $v;
    }
  }

  return null;
}
