@extends('errors::minimal')

@section('title', 'Too many requests')
@section('code', '429')
@section('message', 'Too many requests')
@section('hint', 'Please wait a minute before trying again.')
