@extends('errors::minimal')

@section('title', "You don't have access to this")
@section('code', '403')
@section('message', "You don't have access to this")
@section('hint', 'Your role in this workspace does not include this page. Ask a workspace owner or admin if you need it.')
