<?php

namespace TPG\Trace;

enum EventType: string
{
    case PageView = 'pageview';
    case ScreenView = 'screenview';
    case Request = 'request';
    case Custom = 'custom';
}
